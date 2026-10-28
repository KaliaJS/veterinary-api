<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Les listes fixes des radios annotées (config/imagerie.php), servies à l'app. */
class ReferentielImagerieController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize(DomainePermission::Patients->value);
        $liste = fn (array $valeurs): array => collect($valeurs)->map(fn (string $libelle, string $code): array => ['code' => $code, 'libelle' => $libelle])->values()->all();

        return response()->json(['data' => [
            'regions' => $liste(config('imagerie.regions')),
            'incidences' => $liste(config('imagerie.incidences')),
            'types' => collect(config('imagerie.types'))->map(fn (array $t, string $code): array => ['code' => $code, 'libelle' => $t['libelle'], 'points_min' => $t['points'][0], 'points_max' => $t['points'][1]])->values()->all(),
            'certitudes' => $liste(config('imagerie.certitudes')),
            'etiquettes' => collect(config('imagerie.etiquettes'))->map(fn (array $e, string $code): array => ['code' => $code, 'libelle' => $e[0], 'regions' => $e[1]])->values()->all(),
            'sources' => $liste(config('imagerie.sources')),
            'resultats' => $liste(config('imagerie.resultats')),
        ]]);
    }
}
