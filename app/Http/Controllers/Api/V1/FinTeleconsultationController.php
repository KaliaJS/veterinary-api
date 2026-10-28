<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutRendezVous;
use App\Events\SignalVisio;
use App\Http\Controllers\Api\V1\Concerns\MeneLAppel;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tele\TerminerSessionRequest;
use App\Http\Resources\V1\FactureResource;
use App\Http\Resources\V1\SessionTeleconsultationResource;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\SessionTeleconsultation;
use App\Services\CreationFacture;
use App\Services\ProjectionPlanningTele;
use App\Services\Tele\AvisTransfert;
use App\Support\TempsReel;
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
    use MeneLAppel;

    public function __construct(
        private ProjectionPlanningTele $projection,
        private CreationFacture $creationFacture,
        private AvisTransfert $avis,
    ) {}

    public function __invoke(TerminerSessionRequest $request, SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        $this->authorize('update', $session);

        if ($session->estTerminee()) {
            throw ValidationException::withMessages(['terminee_le' => 'Cette session est déjà terminée.']);
        }
        // Transférée et prise par un collègue : ce n'est plus à ce poste de la terminer
        $this->assurerMeneLAppel($session);
        $transfertEnAttente = $session->transfert_vers_id;

        $facture = DB::transaction(function () use ($request, $session): ?Facture {
            $maintenant = now();

            $session->update([
                'terminee_le' => $maintenant,
                'duree_secondes' => (int) $session->demarre_le->diffInSeconds($maintenant),
                'transfert_vers_id' => null,
                'transfert_demande_le' => null,
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

        // La page du client l'apprend aussitôt (appel vidéo WebRTC, 28.10.2026)
        TempsReel::diffuser(fn () => SignalVisio::dispatch($session->id, 'veterinaire', 'fin', []));
        // Le collègue à qui l'appel était transféré : sa demande tombe
        $userVers = $transfertEnAttente !== null ? Employe::withoutGlobalScopes()->whereKey($transfertEnAttente)->value('user_id') : null;
        if ($userVers !== null) {
            $this->avis->envoyer((int) $userVers, $session, 'termine');
        }

        $resource = new SessionTeleconsultationResource($session->load(SessionTeleconsultationController::relations()));

        if ($facture !== null && Gate::allows('facturation')) {
            $resource->additional(['facture' => new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']))]);
        }

        return $resource;
    }
}
