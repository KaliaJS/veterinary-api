<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\Intervention;
use App\Services\DayOccupancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le remplissage du bloc, jour par jour (bande des jours de l'écran Bloc
 * opératoire et de « Programmer une intervention ») : pour chaque jour,
 * le matin, midi et l'après-midi, vrais quand une intervention y est
 * prévue.
 */
class BlocOccupancyController extends Controller
{
    public function __invoke(Request $request, ContexteClinique $contexte, DayOccupancy $occupancy): JsonResponse
    {
        $this->authorize('viewAny', Intervention::class);
        $fuseau = $contexte->clinique()->fuseau_horaire ?? 'Europe/Zurich';
        [$du, $au] = DayOccupancy::period($request, $fuseau);

        $plages = Intervention::query()
            ->whereBetween('prevu_le', [$du->subDay()->utc(), $au->addDay()->utc()])
            ->get(['prevu_le', 'duree_minutes'])
            ->map(fn (Intervention $i): array => [$i->prevu_le, $i->prevu_le->copy()->addMinutes($i->duree_minutes)]);

        return response()->json(['data' => $occupancy->for($fuseau, $du, $au, $plages)]);
    }
}
