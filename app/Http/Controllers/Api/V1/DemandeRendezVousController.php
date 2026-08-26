<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DrapeauRendezVous;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\FilesAttente\PlacerDepuisFileRequest;
use App\Http\Resources\V1\DemandeRendezVousResource;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\DemandeRendezVous;
use App\Services\CalculateurCreneaux;
use App\Services\MutationRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Demandes de rendez-vous en ligne. « Placer » crée le RDV (`prevu`,
 * drapeau `neuf` — réservation en ligne) et retire l'entrée ;
 * confirmation et refus se traduisent par un simple retrait de la file
 * (le journal « traitées » du panneau est un état d'UI, pas une table).
 * Alimentée par le portail client, à venir : pas de création ici.
 */
class DemandeRendezVousController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DemandeRendezVous::class);

        return DemandeRendezVousResource::collection(
            DemandeRendezVous::query()
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate($this->parPage($request)),
        );
    }

    public function placer(
        PlacerDepuisFileRequest $request,
        DemandeRendezVous $demande,
        CalculateurCreneaux $calculateur,
        MutationRendezVous $mutation,
    ): RendezVousResource {
        $this->authorize('placer', $demande);

        $donnees = $request->validated();

        if (isset($donnees['starts_at'])) {
            $ligneId = (int) $donnees['ligne_planning_id'];
            $debut = CarbonImmutable::parse($donnees['starts_at']);
        } else {
            $creneau = $calculateur->prochainCreneau($calculateur->aujourdHui(), $demande->duree_minutes);

            if ($creneau === null) {
                throw ValidationException::withMessages(['starts_at' => 'Aucun créneau libre aujourd’hui.']);
            }

            [$ligneId, $debut] = [$creneau['ligne_planning_id'], $creneau['debut']];
        }

        $attributs = $mutation->preparerCreation([
            'ligne_planning_id' => $ligneId,
            'starts_at' => $debut,
            'duree_minutes' => $demande->duree_minutes,
            'patient_id' => $demande->patient_id,
            'patient_name' => $demande->patient_name,
            'species' => $demande->species,
            'owner_name' => $demande->owner_name,
            'type' => TypeRendezVous::Consultation,
            'status' => StatutRendezVous::Prevu,
            'flags' => [DrapeauRendezVous::NouveauClient],
            'reason' => $demande->reason,
        ]);

        $rendezVous = DB::transaction(function () use ($mutation, $attributs, $request, $demande) {
            $rendezVous = $mutation->creer($attributs, $request->user());
            $demande->delete();

            return $rendezVous;
        });

        return new RendezVousResource($rendezVous);
    }

    public function destroy(DemandeRendezVous $demande): Response
    {
        $this->authorize('delete', $demande);

        $demande->delete();

        return response()->noContent();
    }
}
