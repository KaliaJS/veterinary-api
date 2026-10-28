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
        $compteRendu = DB::transaction(fn (): CompteRendu => $this->ecrire($rendezVous, $request));

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

            if (collect(['s', 'o', 'a', 'p'])->every(fn (string $k) => trim((string) $compteRendu->{$k}) === '')) {
                throw ValidationException::withMessages(['compte_rendu' => 'Un compte rendu vide ne se signe pas.']);
            }

            $compteRendu->update(['signe_le' => now(), 'signe_par_id' => $signataire->id]);

            return $compteRendu;
        });

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

        $compteRendu = $rendezVous->compteRendu()->lockForUpdate()->first();
        if ($compteRendu?->estSigne()) {
            throw ValidationException::withMessages(['compte_rendu' => 'Ce compte rendu est signé : ajoutez un complément.']);
        }

        if ($compteRendu === null) {
            $compteRendu = $rendezVous->compteRendu()->create([...$texte, 'user_id' => $auteur->id]);
        } else {
            $avant = $compteRendu->only(['s', 'o', 'a', 'p']);
            $compteRendu->fill($texte);
            if ($compteRendu->isDirty(['s', 'o', 'a', 'p'])) {
                $compteRendu->versions()->create([...$avant, 'user_id' => $compteRendu->user_id, 'redigee_le' => $compteRendu->updated_at ?? now()]);
                $compteRendu->user_id = $auteur->id;
            }
            $compteRendu->save();
        }

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

    private function signataire(): Employe
    {
        return app(ContexteClinique::class)->employe()
            ?? throw ValidationException::withMessages(['compte_rendu' => 'Seul un employé de la clinique signe un compte rendu.']);
    }

    private function charger(CompteRendu $compteRendu): CompteRendu
    {
        return $compteRendu->load(['signataire.user', 'complements.signataire.user'])->loadCount('versions');
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
