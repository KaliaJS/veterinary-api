<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Espece;
use App\Enums\Sexe;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Patients\EnregistrerPatientRequest;
use App\Http\Requests\V1\Patients\ModifierPatientRequest;
use App\Http\Resources\V1\PatientResource;
use App\Models\Client;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\ReferralInvitation;
use App\Models\Vaccination;
use App\Rules\NumeroPuce;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Patients — niveau ORGANISATION comme les clients (scope global
 * organisation, partagés entre les cliniques du jeton). Jamais de
 * suppression de fiche. Filtres et tris de l'écran Patients :
 * `filtre` tous|chien|chat|nac|alertes|rappels|referes (référés par une
 * autre clinique dans Veterinary, 09.11.2026), `sexe` male|femelle qui
 * s'y ajoute (10.11.2026), `tri` nom|proprietaire|
 * alertes|rappels|visite|poids et `sens` asc|desc (16.10.2026 : tri par
 * clic sur les en-têtes de colonnes),
 * `q` sur nom, race, puce et propriétaire ; `meta.facettes` porte les
 * compteurs de chaque filtre sur la recherche courante.
 */
class PatientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Patient::class);

        $patients = Patient::query()
            ->with(['client', 'courbePoids', 'rendezVousAVenir'])
            // Les injections pas encore renouvelées : un vaccin renouvelé n'est plus dû (01.11.2026)
            ->withMin(['vaccinations' => fn ($requete) => $requete->nonRenouvelees()], 'echeance_le')
            ->withMax('visites', 'visite_le')
            ->tap(fn (Builder $requete) => $this->rechercher($requete, $request))
            ->tap(fn (Builder $requete) => $this->filtrer($requete, $request))
            ->tap(fn (Builder $requete) => $this->filtrerSexe($requete, $request))
            ->tap(fn (Builder $requete) => $this->trier($requete, $request))
            ->paginate($this->parPage($request));

        return PatientResource::collection($patients)->additional([
            'meta' => ['facettes' => $this->facettes($request)],
        ]);
    }

    /** Le filtre d'espèce ou d'état de l'écran (`filtre`). */
    private function filtrer(Builder $requete, Request $request): void
    {
        match ($request->string('filtre')->toString()) {
            'chien' => $requete->where('espece', Espece::Chien),
            'chat' => $requete->where('espece', Espece::Chat),
            'nac' => $requete->whereNotIn('espece', [Espece::Chien, Espece::Chat]),
            'alertes' => $requete->where(fn (Builder $clause) => $clause
                ->where('est_agressif', true)
                ->orWhere('a_allergies', true)
                ->orWhere('dette', '>', 0)),
            // Les patients référés par une autre clinique dans Veterinary (envoi C, 09.11.2026)
            'referes' => $requete->whereIn('patients.id', ReferralInvitation::query()->select('target_patient_id')->where('status', 'accepted')->whereNotNull('target_patient_id')),
            'rappels' => $requete->whereHas(
                'vaccinations',
                fn (Builder $vaccination) => $vaccination->nonRenouvelees()->where('echeance_le', '<=', $this->horizonRappels()),
            ),
            default => $requete,
        };
    }

    /** Le sexe (`sexe` male|femelle, Jim, 10.11.2026), qui se combine au `filtre` ; une autre valeur ne filtre pas. */
    private function filtrerSexe(Builder $requete, Request $request): void
    {
        $sexe = match ($request->string('sexe')->toString()) {
            'male' => Sexe::Male,
            'femelle' => Sexe::Femelle,
            default => null,
        };

        $requete->when($sexe, fn (Builder $requete, Sexe $sexe) => $requete->where('sexe', $sexe));
    }

    /** Recherche `q` sur nom, race, puce et propriétaire. */
    /**
     * Tri de l écran : `tri` et `sens` (asc par défaut, sauf visite et
     * poids, du plus récent ou du plus lourd). Les valeurs absentes
     * (sans rappel, sans visite, sans pesée) vont toujours en fin.
     */
    private function trier(Builder $requete, Request $request): void
    {
        $tri = $request->string('tri')->toString() ?: 'nom';
        $sens = $request->string('sens')->toString() ?: (in_array($tri, ['visite', 'poids'], true) ? 'desc' : 'asc');
        $sens = $sens === 'desc' ? 'desc' : 'asc';

        match ($tri) {
            'proprietaire' => $requete->orderBy(
                Client::withoutGlobalScopes()->select('nom')->whereColumn('clients.id', 'patients.client_id')->limit(1),
                $sens,
            )->orderBy('nom'),
            'alertes' => $requete
                ->orderByRaw('(case when est_agressif then 1 else 0 end) + (case when a_allergies then 1 else 0 end) + (case when dette > 0 then 1 else 0 end) '.($sens === 'asc' ? 'desc' : 'asc'))
                ->orderBy('nom'),
            'rappels' => $requete
                ->orderByRaw('vaccinations_min_echeance_le is null')
                ->orderBy('vaccinations_min_echeance_le', $sens)
                ->orderBy('nom'),
            'visite' => $requete
                ->orderByRaw('visites_max_visite_le is null')
                ->orderBy('visites_max_visite_le', $sens)
                ->orderBy('nom'),
            'poids' => $requete
                ->orderByRaw('(select poids_kg from pesees where pesees.patient_id = patients.id order by pesee_le desc, id desc limit 1) is null')
                ->orderBy(
                    Pesee::select('poids_kg')->whereColumn('pesees.patient_id', 'patients.id')->orderByDesc('pesee_le')->orderByDesc('id')->limit(1),
                    $sens,
                )
                ->orderBy('nom'),
            default => $requete->orderBy('nom', $sens),
        };
    }

    private function rechercher(Builder $requete, Request $request): void
    {
        if ($request->string('q')->isEmpty()) {
            return;
        }

        $q = '%'.$request->string('q')->lower().'%';

        $requete->where(fn (Builder $clause) => $clause
            ->whereRaw('lower(patients.nom) like ?', [$q])
            ->orWhereRaw('lower(coalesce(patients.race, \'\')) like ?', [$q])
            ->orWhereRaw('lower(coalesce(patients.puce, \'\')) like ?', [$q])
            ->orWhereHas('client', fn (Builder $client) => $client->whereRaw('lower(nom) like ?', [$q])));
    }

    /**
     * Compteurs des filtres de l'écran, sur la recherche courante mais
     * indépendants du filtre actif (chaque onglet affiche le sien) — une
     * requête d'agrégats conditionnels. Les deux groupes se croisent :
     * l'espèce et l'état comptent avec le sexe choisi, le sexe avec
     * l'espèce ou l'état choisi.
     *
     * @return array{tous: int, chien: int, chat: int, nac: int, alertes: int, rappels: int, referes: int, male: int, femelle: int}
     */
    private function facettes(Request $request): array
    {
        $compte = fn (string $condition): string => "sum(case when {$condition} then 1 else 0 end)";

        $ligne = Patient::query()
            ->tap(fn (Builder $requete) => $this->rechercher($requete, $request))
            ->tap(fn (Builder $requete) => $this->filtrerSexe($requete, $request))
            ->selectRaw(implode(', ', [
                'count(*) as tous',
                $compte('patients.espece = ?').' as chien',
                $compte('patients.espece = ?').' as chat',
                $compte('patients.espece not in (?, ?)').' as nac',
                $compte('patients.est_agressif = 1 or patients.a_allergies = 1 or patients.dette > 0').' as alertes',
                $compte('exists (select 1 from vaccinations where vaccinations.patient_id = patients.id and vaccinations.echeance_le <= ? and '.Vaccination::SQL_NON_RENOUVELEE.')').' as rappels',
                $compte("exists (select 1 from referral_invitations where referral_invitations.target_patient_id = patients.id and referral_invitations.status = 'accepted')").' as referes',
            ]), [
                Espece::Chien->value,
                Espece::Chat->value,
                Espece::Chien->value,
                Espece::Chat->value,
                $this->horizonRappels()->toDateString(),
            ])
            ->toBase()
            ->first();

        $sexes = Patient::query()
            ->tap(fn (Builder $requete) => $this->rechercher($requete, $request))
            ->tap(fn (Builder $requete) => $this->filtrer($requete, $request))
            ->selectRaw($compte('patients.sexe = ?').' as male, '.$compte('patients.sexe = ?').' as femelle', [Sexe::Male->value, Sexe::Femelle->value])
            ->toBase()
            ->first();

        return [
            'tous' => (int) ($ligne->tous ?? 0),
            'chien' => (int) ($ligne->chien ?? 0),
            'chat' => (int) ($ligne->chat ?? 0),
            'nac' => (int) ($ligne->nac ?? 0),
            'alertes' => (int) ($ligne->alertes ?? 0),
            'rappels' => (int) ($ligne->rappels ?? 0),
            'referes' => (int) ($ligne->referes ?? 0),
            'male' => (int) ($sexes->male ?? 0),
            'femelle' => (int) ($sexes->femelle ?? 0),
        ];
    }

    /** Un rappel vaccinal « proche » : échéance dans les 30 jours (ou passée). */
    private function horizonRappels(): Carbon
    {
        return aujourdhui()->addDays(30);
    }

    public function store(EnregistrerPatientRequest $request): PatientResource
    {
        $this->authorize('create', Patient::class);

        $donnees = $request->validated();

        if (isset($donnees['puce'])) {
            $donnees['puce'] = NumeroPuce::formater($donnees['puce']);
        }

        $patient = Patient::create([
            ...$donnees,
            'client_depuis' => $donnees['client_depuis'] ?? aujourdhui()->year,
        ]);

        return new PatientResource($patient->load(['client', 'referringVet']));
    }

    public function show(Patient $patient): PatientResource
    {
        $this->authorize('view', $patient);

        // Les signataires (vaccins, visites) peuvent être d'une autre
        // clinique de l'organisation : leur scope clinique est levé
        // pour afficher le nom — lecture seule.
        $patient->load([
            'client',
            // Le vétérinaire référent, qui a pesé, qui a ajouté un document (06.11.2026)
            'referringVet',
            'pesees' => fn ($requete) => $requete->orderBy('pesee_le')->orderBy('id'),
            'pesees.employe',
            // Renouvelée ou non, et le dernier rappel parti : le carnet le dit d'après le serveur (01.11.2026)
            'vaccinations' => fn ($requete) => $requete->orderBy('echeance_le')->withMax('rappelsEnvoyes as rappel_envoye_le', 'envoye_le'),
            'vaccinations.employe' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'visites' => fn ($requete) => $requete->orderByDesc('visite_le')->orderByDesc('id'),
            'visites.employe' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'documents' => fn ($requete) => $requete->orderByDesc('etabli_le')->orderByDesc('id'),
            'documents.employe',
            'relevesOphtalmologiques' => fn ($requete) => $requete->orderByDesc('releve_le')->orderByDesc('id'),
            'relevesDentaires',
            'courbePoids',
            'rendezVousAVenir',
        ])->loadMin(['vaccinations' => fn ($requete) => $requete->nonRenouvelees()], 'echeance_le')->loadMax('visites', 'visite_le');

        return new PatientResource($patient);
    }

    public function update(ModifierPatientRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('update', $patient);

        $donnees = $request->validated();

        // L identité peut être réservée par le propriétaire (droits sur l animal) ;
        // alertes, traitement, rappels restent à la clinique
        if (array_intersect_key($donnees, array_flip(Patient::CHAMPS_IDENTITE)) !== []) {
            $this->authorize('updateIdentity', $patient);
        }

        if (isset($donnees['puce'])) {
            $donnees['puce'] = NumeroPuce::formater($donnees['puce']);
        }

        // Changement de propriétaire (décisions de Jim, 03.11.2026) : un compte qui avait
        // réclamé l'animal par sa puce, et qui n'est pas celui de la nouvelle fiche, le perd ;
        // l'animal suit la nouvelle fiche et n'a toujours qu'un seul compte
        if (array_key_exists('client_id', $donnees) && (int) $donnees['client_id'] !== (int) $patient->client_id && $patient->proprietaire_id !== null
            && Client::withoutGlobalScopes()->whereKey($donnees['client_id'])->value('user_id') !== $patient->proprietaire_id) {
            $donnees['proprietaire_id'] = null;
        }

        $patient->update($donnees);

        // La bande du poids de forme se lit avec sa position, d'après la dernière pesée
        return new PatientResource($patient->load(['client', 'courbePoids', 'referringVet']));
    }
}
