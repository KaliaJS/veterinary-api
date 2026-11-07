<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerCompteRenduRequest;
use App\Http\Resources\V1\CompteRenduResource;
use App\Http\Resources\V1\CompteRenduVersionResource;
use App\Models\CompteRendu;
use App\Models\Employe;
use App\Models\EnregistrementConsultation;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Compte rendu SOAP — un par rendez-vous, upsert au PUT. Comme tout le
 * dossier médical, il est de NIVEAU ORGANISATION : le rendez-vous
 * porteur est résolu parmi toutes les cliniques de l'organisation du
 * jeton (pas de binding implicite, qui le bornerait à la clinique).
 *
 * Depuis le 23.10.2026 : le brouillon s'écrit avec le domaine patients,
 * chaque modification garde la version remplacée ; la signature (droit
 * `signer_comptes_rendus`, l'employé du jeton) fige le texte, et ce qui
 * vient ensuite s'ajoute en complément, daté et signé.
 */
class CompteRenduController extends Controller
{
    /**
     * Une écriture victime d'un interblocage MySQL (la fin de consultation verrouille
     * la facture puis le rendez-vous) est rejouée, jamais une erreur 500 (07.11.2026)
     */
    private const ESSAIS = 3;

    public function afficher(string $rendezVous): CompteRenduResource
    {
        $this->authorize('viewAny', CompteRendu::class);

        return new CompteRenduResource($this->charger(
            $this->rendezVousDeLOrganisation($rendezVous)->compteRendu()->firstOrFail(),
        ));
    }

    public function enregistrer(EnregistrerCompteRenduRequest $request, string $rendezVous): CompteRenduResource
    {
        $this->authorize('create', CompteRendu::class);

        $rendezVous = $this->rendezVousDeLOrganisation($rendezVous);
        $compteRendu = DB::transaction(fn (): CompteRendu => $this->ecrire($rendezVous, $request), self::ESSAIS);

        return new CompteRenduResource($this->charger($compteRendu));
    }

    /** Signe le compte rendu, avec le texte envoyé s'il y en a un. */
    public function signer(EnregistrerCompteRenduRequest $request, string $rendezVous): CompteRenduResource
    {
        $this->authorize('create', CompteRendu::class);
        Gate::authorize(DomainePermission::SignerComptesRendus->value);
        $signataire = $this->signataire();

        $rendezVous = $this->rendezVousDeLOrganisation($rendezVous);
        $compteRendu = DB::transaction(function () use ($request, $rendezVous, $signataire): CompteRendu {
            $compteRendu = $this->ecrire($rendezVous, $request);

            if (collect(['s', 'o', 'a', 'p', 'tool_notes'])->every(fn (string $k) => trim((string) $compteRendu->{$k}) === '')) {
                throw ValidationException::withMessages(['compte_rendu' => 'Un compte rendu vide ne se signe pas.']);
            }

            $compteRendu->update(['signe_le' => now(), 'signe_par_id' => $signataire->id]);

            return $compteRendu;
        }, self::ESSAIS);

        return new CompteRenduResource($this->charger($compteRendu));
    }

    /** Un complément à un compte rendu signé : daté, signé, jamais modifié. */
    public function completer(Request $request, string $rendezVous): CompteRenduResource
    {
        $this->authorize('create', CompteRendu::class);
        Gate::authorize(DomainePermission::SignerComptesRendus->value);
        $texte = trim((string) $request->validate(['texte' => ['required', 'string', 'max:5000']])['texte']);
        $signataire = $this->signataire();

        $compteRendu = $this->rendezVousDeLOrganisation($rendezVous)->compteRendu()->firstOrFail();
        if (! $compteRendu->estSigne()) {
            throw ValidationException::withMessages(['texte' => 'Le compte rendu n’est pas encore signé : modifiez-le directement.']);
        }
        if ($texte === '') {
            throw ValidationException::withMessages(['texte' => 'Le complément est vide.']);
        }

        $compteRendu->complements()->create(['texte' => $texte, 'signe_par_id' => $signataire->id]);

        return new CompteRenduResource($this->charger($compteRendu));
    }

    /**
     * Une ligne notée par un calculateur (vérification du 04.11.2026) :
     * ajoutée à la suite des lignes déjà notées, sous verrou, jamais dans le
     * texte SOAP que le brouillon d'un enregistrement réécrit. Signé, le
     * compte rendu la reçoit en complément (droit de signer).
     */
    public function noterOutil(Request $request, string $rendezVous): CompteRenduResource
    {
        $this->authorize('create', CompteRendu::class);
        $texte = trim((string) $request->validate(['texte' => ['required', 'string', 'max:2000']])['texte']);
        if ($texte === '') {
            throw ValidationException::withMessages(['texte' => 'La ligne est vide.']);
        }
        /** @var User $auteur */
        $auteur = $request->user();
        $rendezVous = $this->rendezVousDeLOrganisation($rendezVous);

        $compteRendu = DB::transaction(function () use ($rendezVous, $texte, $auteur): CompteRendu {
            $this->verrouiller($rendezVous);
            // Deux premières lignes en même temps : l'index unique départage, l'autre ligne est reprise
            $rendezVous->compteRendu()->firstOrCreate([], ['user_id' => $auteur->id]);
            $compteRendu = $rendezVous->compteRendu()->lockForUpdate()->firstOrFail();

            if ($compteRendu->estSigne()) {
                Gate::authorize(DomainePermission::SignerComptesRendus->value);
                $compteRendu->complements()->create(['texte' => $texte, 'signe_par_id' => $this->signataire()->id]);

                return $compteRendu;
            }

            $avant = trim((string) $compteRendu->tool_notes);
            $compteRendu->update(['tool_notes' => $avant === '' ? $texte : $avant."\n".$texte]);

            return $compteRendu;
        }, self::ESSAIS);

        return new CompteRenduResource($this->charger($compteRendu));
    }

    /** Les versions remplacées avant la signature, de la plus récente à la plus ancienne. */
    public function historique(string $rendezVous): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompteRendu::class);

        $compteRendu = $this->rendezVousDeLOrganisation($rendezVous)->compteRendu()->firstOrFail();

        return CompteRenduVersionResource::collection($compteRendu->versions()->with('user')->get());
    }

    /**
     * Écrit le brouillon (refusé s'il est signé) ; la version remplacée
     * est gardée quand le texte change.
     */
    private function ecrire(RendezVous $rendezVous, EnregistrerCompteRenduRequest $request): CompteRendu
    {
        $texte = $request->safe()->only(['s', 'o', 'a', 'p']);
        /** @var User $auteur */
        $auteur = $request->user();

        $this->verrouiller($rendezVous);
        $compteRendu = $rendezVous->compteRendu()->lockForUpdate()->first();
        // Deux premiers enregistrements en même temps (vérification du 06.11.2026) :
        // l'index unique départage ; l'autre ligne, née entre-temps, est reprise sous
        // verrou et passe au contrôle de version (409), jamais une erreur 500
        $estNe = false;
        if ($compteRendu === null) {
            $compteRendu = $rendezVous->compteRendu()->createOrFirst([], ['user_id' => $auteur->id]);
            $estNe = $compteRendu->wasRecentlyCreated;
            $compteRendu = $rendezVous->compteRendu()->lockForUpdate()->firstOrFail();
        }
        if ($compteRendu->estSigne()) {
            // Signé pendant qu'on écrivait (07.11.2026) : le refus porte le compte rendu signé
            if ($request->validated('version') !== null) {
                $this->refuser($compteRendu, 'Ce compte rendu a été signé entre-temps : ajoutez votre texte en complément.');
            }
            throw ValidationException::withMessages(['compte_rendu' => 'Ce compte rendu est signé : ajoutez un complément.']);
        }

        $this->refuserSiDepasse($compteRendu, $texte, $request->validated('version'));

        $avant = $compteRendu->only(['s', 'o', 'a', 'p']);
        $compteRendu->fill($texte);
        // Un compte rendu né d'une ligne notée n'a encore aucun texte : pas de version vide (04.11.2026)
        $etaitVide = collect($avant)->every(fn ($v): bool => trim((string) $v) === '');
        if ($compteRendu->isDirty(['s', 'o', 'a', 'p'])) {
            if (! $etaitVide) {
                $compteRendu->versions()->create([...$avant, 'user_id' => $compteRendu->user_id, 'redigee_le' => $compteRendu->updated_at ?? now()]);
            }
            $compteRendu->user_id = $auteur->id;
            $compteRendu->version = $compteRendu->version + 1;
            $compteRendu->texte_modifie_le = now();
        }
        $compteRendu->save();
        // Né de cet enregistrement : 201, comme avant
        $compteRendu->wasRecentlyCreated = $estNe;

        // Relu et enregistré par le vétérinaire : le brouillon de
        // l'enregistrement est validé
        if ($request->validated('enregistrement_id')) {
            $valide = EnregistrementConsultation::query()
                ->whereKey($request->validated('enregistrement_id'))
                ->where('rendez_vous_id', $rendezVous->id)
                ->where('statut', StatutEnregistrement::Pret->value)
                ->update(['statut' => StatutEnregistrement::Valide->value, 'valide_le' => now()]);
            // Les autres postes l'apprennent (temps réel, 28.10.2026)
            if ($valide === 1) {
                EnregistrementConsultation::annoncer((int) $request->validated('enregistrement_id'));
            }
        }

        return $compteRendu;
    }

    /**
     * Contrôle de version (choix de Jim, 06.11.2026) : quelqu'un a enregistré
     * un autre texte depuis l'ouverture. Refusé (409) au lieu d'écraser ; la
     * réponse porte le texte du serveur, qui l'a modifié et quand. Un texte
     * identique à celui du serveur passe : il n'écrase rien.
     *
     * @param  array<string, mixed>  $texte
     */
    private function refuserSiDepasse(?CompteRendu $compteRendu, array $texte, mixed $versionOuverte): void
    {
        if ($versionOuverte === null || (int) $versionOuverte === ($compteRendu->version ?? 0)) {
            return;
        }
        $change = collect($texte)->contains(fn ($v, string $k): bool => trim((string) $v) !== trim((string) $compteRendu?->{$k}));
        if (! $change || $compteRendu === null) {
            return;
        }

        $this->refuser($compteRendu, 'Ce compte rendu a été modifié par quelqu’un d’autre depuis que vous l’avez ouvert.');
    }

    /** Le refus (409) avec le compte rendu tel qu'il est sur le serveur */
    private function refuser(CompteRendu $compteRendu, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'data' => (new CompteRenduResource($this->charger($compteRendu)))->resolve(),
        ], 409));
    }

    /**
     * Le rendez-vous verrouillé d'abord (deuxième vérification du 07.11.2026) :
     * sous MySQL, deux premiers enregistrements qui verrouillent un compte rendu
     * encore inexistant posent des verrous d'intervalle compatibles, puis leurs
     * INSERT se bloquent l'un l'autre (1213). Le rendez-vous, lui, existe : son
     * verrou fait passer les écritures l'une après l'autre.
     */
    private function verrouiller(RendezVous $rendezVous): void
    {
        RendezVous::withoutGlobalScopes()->whereKey($rendezVous->id)->lockForUpdate()->first();
    }

    private function signataire(): Employe
    {
        return app(ContexteClinique::class)->employe()
            ?? throw ValidationException::withMessages(['compte_rendu' => 'Seul un employé de la clinique signe un compte rendu.']);
    }

    private function charger(CompteRendu $compteRendu): CompteRendu
    {
        return $compteRendu->load(['user', 'signataire.user', 'complements.signataire.user'])->loadCount('versions');
    }

    /**
     * Le scope clinique du rendez-vous est levé mais la requête reste
     * bornée à l'organisation du jeton : hors organisation, 404.
     */
    private function rendezVousDeLOrganisation(string $id): RendezVous
    {
        return RendezVous::withoutGlobalScope('clinique')
            ->whereHas('clinique.societe', fn (Builder $societe) => $societe
                ->where('organisation_id', app(ContexteClinique::class)->organisationId()))
            ->findOrFail($id);
    }
}
