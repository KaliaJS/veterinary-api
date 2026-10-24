<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RendezVous\DeplacerRendezVousRequest;
use App\Http\Requests\V1\RendezVous\RedimensionnerRendezVousRequest;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Services\CalculateurCreneaux;
use App\Services\MutationRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Gestes de la grille : déplacement (durée conservée) et
 * redimensionnement. Un tampon n'est ni déplaçable ni
 * redimensionnable ; le chevauchement n'est jamais refusé.
 */
class RendezVousPlacementController extends Controller
{
    public function __construct(private MutationRendezVous $mutation) {}

    public function deplacer(DeplacerRendezVousRequest $request, RendezVous $rendezVous, CalculateurCreneaux $calculateur): RendezVousResource
    {
        $this->authorize('update', $rendezVous);
        $this->refuserTampon($rendezVous);

        $donnees = $request->validated();

        $duree = (int) $rendezVous->starts_at->diffInMinutes($rendezVous->ends_at);
        $debut = isset($donnees['starts_at'])
            ? CarbonImmutable::parse($donnees['starts_at'])
            : $rendezVous->starts_at->toImmutable();

        $attributs = [
            'starts_at' => $debut,
            'ends_at' => $debut->addMinutes($duree),
        ];

        if (isset($donnees['ligne_planning_id'])) {
            $attributs['ligne_planning_id'] = $donnees['ligne_planning_id'];
        }

        // Glissé sur la colonne d'un praticien absent : refusé, comme une
        // création ou une modification (24.10.2026)
        $ligne = LignePlanning::findOrFail($attributs['ligne_planning_id'] ?? $rendezVous->ligne_planning_id);
        if ($calculateur->ligneEstBloquee($ligne, $debut)) {
            throw ValidationException::withMessages([
                'ligne_planning_id' => 'Colonne bloquée par une absence validée du praticien.',
            ]);
        }

        // Le front n'audite qu'un `startsAt` (+ `laneId`) par
        // déplacement — le `endsAt` suit la durée, muet.
        $this->mutation->appliquer($rendezVous, $attributs, $request->user(), ['ends_at']);

        return new RendezVousResource($rendezVous);
    }

    public function redimensionner(RedimensionnerRendezVousRequest $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);
        $this->refuserTampon($rendezVous);

        $duree = (int) $request->validated('duree_minutes');

        $this->mutation->appliquer(
            $rendezVous,
            ['ends_at' => $rendezVous->starts_at->toImmutable()->addMinutes($duree)],
            $request->user(),
        );

        return new RendezVousResource($rendezVous);
    }

    private function refuserTampon(RendezVous $rendezVous): void
    {
        if ($rendezVous->estTampon()) {
            throw ValidationException::withMessages([
                'type' => 'Un créneau tampon n’est ni déplaçable ni redimensionnable.',
            ]);
        }
    }
}
