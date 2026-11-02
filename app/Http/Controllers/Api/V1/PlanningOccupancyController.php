<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Models\RendezVous;
use App\Services\DayOccupancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le remplissage du planning, jour par jour (bande des jours du
 * Planning) : pour chaque jour, le matin, midi et l'après-midi, vrais
 * quand un rendez-vous non annulé y tombe.
 */
class PlanningOccupancyController extends Controller
{
    public function __invoke(Request $request, ContexteClinique $contexte, DayOccupancy $occupancy): JsonResponse
    {
        $this->authorize('viewAny', RendezVous::class);
        $fuseau = $contexte->clinique()->fuseau_horaire ?? 'Europe/Zurich';
        [$du, $au] = DayOccupancy::period($request, $fuseau);

        $plages = RendezVous::query()
            ->where('starts_at', '<', $au->addDay()->utc())
            ->where('ends_at', '>', $du->utc())
            ->where('status', '!=', StatutRendezVous::Annule)
            ->get(['starts_at', 'ends_at'])
            ->map(fn (RendezVous $r): array => [$r->starts_at, $r->ends_at]);

        return response()->json(['data' => $occupancy->for($fuseau, $du, $au, $plages)]);
    }
}
