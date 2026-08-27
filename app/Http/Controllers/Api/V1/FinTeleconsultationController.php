<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tele\TerminerSessionRequest;
use App\Http\Resources\V1\FactureResource;
use App\Http\Resources\V1\SessionTeleconsultationResource;
use App\Models\Facture;
use App\Models\SessionTeleconsultation;
use App\Services\CreationFacture;
use App\Services\ProjectionPlanningTele;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Fin d'appel (teleEnd du front) : la durée est calculée par le
 * serveur et la session figée. « Terminer » (`cloturer`) clôt le RDV
 * `tele` lié — `fini`, et facture de l'acte comme tout RDV fini (effet
 * système, débrayable par `sans_facture`, exposé selon le Gate
 * facturation) ; « Quitter » laisse le rendez-vous en cours, un nouvel
 * appel reste possible.
 */
class FinTeleconsultationController extends Controller
{
    public function __construct(
        private ProjectionPlanningTele $projection,
        private CreationFacture $creationFacture,
    ) {}

    public function __invoke(TerminerSessionRequest $request, SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        $this->authorize('update', $session);

        if ($session->estTerminee()) {
            throw ValidationException::withMessages(['terminee_le' => 'Cette session est déjà terminée.']);
        }

        $facture = DB::transaction(function () use ($request, $session): ?Facture {
            $maintenant = now();

            $session->update([
                'terminee_le' => $maintenant,
                'duree_secondes' => (int) $session->demarre_le->diffInSeconds($maintenant),
            ]);

            if (! $request->boolean('cloturer')) {
                return null;
            }

            $this->projection->cloturer($session, $request->user());

            $rendezVous = $session->rendezVous;

            if ($rendezVous === null || $rendezVous->status !== StatutRendezVous::Fini) {
                return null;
            }

            $session->update(['cloture_rendez_vous' => true]);

            if ($request->boolean('sans_facture')) {
                return null;
            }

            return $this->creationFacture->depuisRendezVous($rendezVous, $request->user());
        });

        $resource = new SessionTeleconsultationResource($session->load(SessionTeleconsultationController::relations()));

        if ($facture !== null && Gate::allows('facturation')) {
            $resource->additional(['facture' => new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']))]);
        }

        return $resource;
    }
}
