<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\ModifierGardeRequest;
use App\Models\Employe;
use App\Models\GardeWeekend;
use App\Services\RotationGardes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gardes de week-end de la clinique du jeton : la rotation est dérivée
 * (semaine modulo l'effectif vétérinaire), seule une réattribution est
 * stockée — elle prime, et l'échange du front est un upsert.
 */
class GardeWeekendController extends Controller
{
    public function __construct(private RotationGardes $rotation) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employe::class);

        return response()->json(['data' => $this->rotation->prochainsWeekends()]);
    }

    public function modifier(ModifierGardeRequest $request): JsonResponse
    {
        $this->authorize('organiserGardes', Employe::class);

        GardeWeekend::updateOrCreate(
            ['samedi_le' => $request->validated('samedi_le')],
            ['employe_id' => $request->validated('employe_id')],
        );

        return response()->json(['data' => $this->rotation->prochainsWeekends()]);
    }
}
