<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Support\Localites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /localites?npa=1020 — le NPA remplit la localité (fenêtre « Nouveau
 * propriétaire », 03.11.2026). Répertoire officiel des localités de
 * swisstopo, hors ligne : resources/data/localites.json (source, date et
 * licence dans le README à côté). Une donnée publique, pour qui crée un
 * propriétaire (droit Patients) ou une clinique (droit Organisation, 04.11.2026).
 * Plusieurs localités pour un NPA : la principale d'abord.
 */
class LocalityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Gate::any([DomainePermission::Patients->value, DomainePermission::Organisation->value]), 403);

        $npa = $request->validate(['npa' => ['required', 'digits:4']])['npa'];

        return response()->json(['data' => array_map(
            fn (array $locality): array => ['npa' => (string) $npa, 'localite' => $locality[0], 'canton' => $locality[1]],
            Localites::duNpa((string) $npa),
        )]);
    }
}
