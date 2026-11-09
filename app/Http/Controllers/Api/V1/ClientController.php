<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Clients\EnregistrerClientRequest;
use App\Http\Requests\V1\Clients\ModifierClientRequest;
use App\Http\Resources\V1\ClientResource;
use App\Models\Client;
use App\Models\RendezVous;
use App\Services\Clients\OwnerSpaceInvitations;
use App\Services\Facturation\PatientDebts;
use App\Support\Telephone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Propriétaires — niveau ORGANISATION : la liste est la même depuis
 * toutes les cliniques du jeton (scope global organisation). Jamais de
 * suppression. Les agrégats de l'écran (solde dû = reste à payer de ses factures dues,
 * no-shows dérivés des RDV, client depuis = min des fiches) sont
 * calculés en requête, pas stockés.
 */
class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::query()
            ->with('patientsParNom')
            ->tap(fn (Builder $requete) => $this->avecAgregats($requete))
            ->when($request->string('q')->isNotEmpty(), function (Builder $requete) use ($request): void {
                $q = '%'.$request->string('q')->lower().'%';
                $requete->where(fn (Builder $clause) => $clause
                    ->whereRaw('lower(prenom) like ?', [$q])
                    ->orWhereRaw('lower(nom) like ?', [$q])
                    ->orWhereRaw('lower(coalesce(tel, \'\')) like ?', [$q])
                    ->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$q])
                    ->orWhereRaw('lower(coalesce(localite, \'\')) like ?', [$q])
                    ->orWhereHas('patients', fn (Builder $patient) => $patient->whereRaw('lower(nom) like ?', [$q])));
            })
            // Le client d'un numéro, quelle que soit son écriture (« 079 123 45 67 »,
            // « +41 79 123 45 67 », « 0041791234567 ») : un mobile déjà connu (03.11.2026)
            // Les exploitations d'animaux de rente (09.11.2026)
            ->when($request->boolean('exploitation'), fn (Builder $requete) => $requete->where('is_farm', true))
            ->when($request->string('tel')->isNotEmpty(), fn (Builder $requete) => $requete
                ->where('phone_e164', Telephone::international($request->string('tel')->toString()) ?? '-'))
            ->when(
                $request->string('tri')->toString() === 'localite',
                fn (Builder $requete) => $requete->orderBy('localite')->orderBy('nom'),
            )
            ->when(
                $request->string('tri')->toString() === 'animaux',
                fn (Builder $requete) => $requete->orderByDesc('patients_count'),
            )
            ->when(
                ! in_array($request->string('tri')->toString(), ['localite', 'animaux'], true),
                fn (Builder $requete) => $requete->orderBy('nom')->orderBy('prenom'),
            )
            ->paginate($this->parPage($request));

        return ClientResource::collection($clients);
    }

    public function store(EnregistrerClientRequest $request): ClientResource
    {
        $this->authorize('create', Client::class);

        $data = $request->validated();
        $invite = (bool) ($data['invite_to_space'] ?? false);
        unset($data['invite_to_space']);

        // Inviter dans son espace (décision de Jim, 03.11.2026, refait après
        // vérification) : rien n'est rattaché et aucun compte n'est regardé ;
        // l'invitation attend sur la fiche, le mail part après l'enregistrement
        $clinic = app(ContexteClinique::class)->clinique();
        if ($invite && $clinic === null) {
            throw ValidationException::withMessages(['invite_to_space' => 'Une invitation part au nom d’une clinique : ouvrez la session d’une clinique.']);
        }
        $client = DB::transaction(function () use ($data, $invite, $clinic): Client {
            $client = Client::create($data);
            if ($invite && $client->email !== null) {
                OwnerSpaceInvitations::invite($client, $client->email, $clinic);
            }

            return $client;
        });

        return new ClientResource($client->refresh());
    }

    public function show(Client $client): ClientResource
    {
        $this->authorize('view', $client);

        return new ClientResource(
            $this->avecAgregats($client->newQuery())->with('patients')->findOrFail($client->id),
        );
    }

    public function update(ModifierClientRequest $request, Client $client): ClientResource
    {
        $this->authorize('update', $client);

        $donnees = $request->validated();

        // La ligne verrouillée le temps d'écrire et de relire : deux
        // enregistrements qui se croisent prennent chacun leur version, et
        // chaque réponse sert la fiche telle qu'elle l'a écrite (03.11.2026)
        return DB::transaction(function () use ($client, $donnees): ClientResource {
            $locked = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            // Avec un compte, ses choix dans son espace font foi (décision de Jim,
            // 03.11.2026) ; lu sous le verrou, un compte rattaché à l'instant compte
            if ($locked->user_id !== null) {
                foreach (['rappels_sms', 'rappels_email', 'muted_reminders', 'language', 'accepts_news'] as $champ) {
                    if (array_key_exists($champ, $donnees)) {
                        throw ValidationException::withMessages([$champ => 'Réglé par le propriétaire dans son espace.']);
                    }
                }
            }
            if (array_key_exists('photo_consent', $donnees)) {
                $donnees['photo_consent_at'] = $donnees['photo_consent'] ? ($locked->photo_consent_at ?? now()) : null;
                unset($donnees['photo_consent']);
            }
            $locked->update($donnees);

            return new ClientResource($this->avecAgregats($locked->newQuery())->with('patients')->findOrFail($locked->id));
        });
    }

    /**
     * Le compteur de no-shows est celui du propriétaire, toutes
     * cliniques de l'organisation confondues — le scope clinique des
     * RDV est levé dans la sous-requête (le client borne déjà à
     * l'organisation).
     */
    private function avecAgregats(Builder $requete): Builder
    {
        return $requete
            ->withCount('patients')
            // Le solde dû : le reste à payer de ses factures dues, factures sans animal comprises (03.11.2026)
            ->selectRaw(PatientDebts::ownerBalanceSql().' as solde_du')
            ->withMin('patients', 'client_depuis')
            ->withCount(['rendezVous as no_shows_count' => fn (Builder $rendezVous) => $rendezVous
                ->withoutGlobalScope('clinique')
                // Jamais les absences chez une autre organisation
                ->whereIn('rendez_vous.clinique_id', RendezVous::cliniquesDe(app(ContexteClinique::class)->organisationId()))
                ->where('status', StatutRendezVous::Noshow)]);
    }
}
