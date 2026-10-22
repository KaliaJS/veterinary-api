<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\RendezVous;
use App\Services\SemaineDisponible;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le calendrier de la clinique pour placer ou proposer une heure (cloche
 * du planning) : la semaine demandée, ses heures libres ou prises pour la
 * durée donnée, sans la limite d'un mois du calendrier des clients (trois
 * ans).
 */
class SemainePlanningController extends Controller
{
    private const int ANNEES = 3;

    public function __invoke(Request $request, ContexteClinique $contexte, SemaineDisponible $semaines): JsonResponse
    {
        $this->authorize('viewAny', RendezVous::class);
        $donnees = $request->validate([
            'semaine' => ['nullable', 'date_format:Y-m-d'],
            'duree' => ['nullable', 'integer', 'min:10', 'max:240'],
        ]);

        $fiche = $contexte->clinique();
        $limite = CarbonImmutable::today($fiche->fuseau_horaire ?? 'Europe/Zurich')->addYears(self::ANNEES);

        return response()->json(['data' => $semaines->pour($fiche, $donnees['semaine'] ?? null, $limite, (int) ($donnees['duree'] ?? SemaineDisponible::PAS_MINUTES))]);
    }
}
