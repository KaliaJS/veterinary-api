<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Disponibilites\DisponibilitesRequest;
use App\Models\RendezVous;
use App\Services\CalculateurCreneaux;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Bornes du jour (zones « FERMÉ » : avant l'ouverture, après la
 * fermeture, la pause) et créneaux libres par ligne — calculés depuis
 * les horaires de la clinique.
 */
class DisponibiliteController extends Controller
{
    public function __invoke(DisponibilitesRequest $request, CalculateurCreneaux $calculateur): JsonResponse
    {
        $this->authorize('viewAny', RendezVous::class);

        $donnees = $request->validated();
        $jour = CarbonImmutable::parse($donnees['date'])->startOfDay();

        $bornes = $calculateur->bornesDuJour($calculateur->horaires(), $jour);

        $enHeure = fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

        $creneaux = $bornes['fermee'] ? collect() : $calculateur->creneauxLibres(
            $jour,
            (int) ($donnees['duree_minutes'] ?? CalculateurCreneaux::TROU_MINIMAL_MINUTES),
            isset($donnees['ligne_planning_id']) ? (int) $donnees['ligne_planning_id'] : null,
        );

        return response()->json(['data' => [
            'date' => $jour->toDateString(),
            'fuseau_horaire' => $calculateur->fuseau(),
            'fermee' => $bornes['fermee'],
            'ouverture' => $bornes['fermee'] ? null : $enHeure($bornes['ouverture']),
            'fermeture' => $bornes['fermee'] ? null : $enHeure($bornes['fermeture']),
            'pause' => $bornes['pause'] === null ? null : [
                'debut' => $enHeure($bornes['pause'][0]),
                'fin' => $enHeure($bornes['pause'][1]),
            ],
            'creneaux' => $creneaux->map(fn (array $creneau): array => [
                'ligne_planning_id' => $creneau['ligne_planning_id'],
                'debut' => $creneau['debut']->toISOString(),
                'fin' => $creneau['fin']->toISOString(),
            ])->all(),
        ]]);
    }
}
