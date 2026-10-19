<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutEnregistrement;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerCompteRenduRequest;
use App\Http\Resources\V1\CompteRenduResource;
use App\Models\CompteRendu;
use App\Models\EnregistrementConsultation;
use App\Models\RendezVous;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compte rendu SOAP — un par rendez-vous, upsert au PUT, signataire =
 * utilisateur du dernier enregistrement. Comme tout le dossier
 * médical, il est de NIVEAU ORGANISATION : le rendez-vous porteur est
 * résolu parmi toutes les cliniques de l'organisation du jeton (pas de
 * binding implicite, qui le bornerait à la clinique).
 */
class CompteRenduController extends Controller
{
    public function afficher(string $rendezVous): CompteRenduResource
    {
        $this->authorize('viewAny', CompteRendu::class);

        return new CompteRenduResource(
            $this->rendezVousDeLOrganisation($rendezVous)->compteRendu()->firstOrFail(),
        );
    }

    public function enregistrer(EnregistrerCompteRenduRequest $request, string $rendezVous): CompteRenduResource
    {
        $this->authorize('create', CompteRendu::class);

        $rendezVous = $this->rendezVousDeLOrganisation($rendezVous);
        $compteRendu = $rendezVous->compteRendu()->updateOrCreate([], [
            ...$request->safe()->only(['s', 'o', 'a', 'p']),
            'user_id' => $request->user()->id,
        ]);

        // Relu et enregistré par le vétérinaire : le brouillon de
        // l'enregistrement est validé
        if ($request->validated('enregistrement_id')) {
            EnregistrementConsultation::query()
                ->whereKey($request->validated('enregistrement_id'))
                ->where('rendez_vous_id', $rendezVous->id)
                ->where('statut', StatutEnregistrement::Pret->value)
                ->update(['statut' => StatutEnregistrement::Valide->value, 'valide_le' => now()]);
        }

        return new CompteRenduResource($compteRendu);
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
