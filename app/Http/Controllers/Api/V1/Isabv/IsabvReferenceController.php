<?php

namespace App\Http\Controllers\Api\V1\Isabv;

use App\Http\Controllers\Controller;
use App\Models\IsabvCode;
use App\Models\IsabvPreparation;
use App\Services\Isabv\DeclarationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les listes de l'OSAV pour saisir une déclaration SI ABV (08.11.2026) :
 * espèces et catégories d'utilisation, fréquences par formulaire, voies,
 * clés de diagnostic d'une espèce, préparations à déclarer.
 */
class IsabvReferenceController extends Controller
{
    public function index(): JsonResponse
    {
        $codes = IsabvCode::query()->whereIn('kind', [IsabvCode::KIND_SPECIES, IsabvCode::KIND_FREQUENCY, IsabvCode::KIND_ROUTE])->get();
        $parents = $codes->where('kind', IsabvCode::KIND_SPECIES)->pluck('parent_code')->filter()->flip();
        $natural = fn ($a, $b) => strnatcmp($a->code, $b->code);

        return response()->json(['data' => [
            'species' => $codes->where('kind', IsabvCode::KIND_SPECIES)->sort($natural)->values()->map(fn (IsabvCode $c) => [
                'code' => $c->code, 'parent' => $c->parent_code, 'label' => $c->label_fr, 'is_leaf' => ! isset($parents[$c->code]),
            ]),
            'frequencies' => $codes->where('kind', IsabvCode::KIND_FREQUENCY)->whereIn('category', ['EH', 'EN'])
                ->sortByDesc('per_day')->values()->map(fn (IsabvCode $c) => ['code' => $c->code, 'form' => $c->category, 'label' => $c->label_fr, 'per_day' => $c->per_day]),
            'routes' => $codes->where('kind', IsabvCode::KIND_ROUTE)->sortBy('label_fr')->values()->map(fn (IsabvCode $c) => ['code' => $c->code, 'label' => $c->label_fr]),
        ]]);
    }

    /** Les clés de diagnostic d'une espèce ou catégorie d'utilisation, en arbre plat (niveau et parent) */
    public function diagnoses(Request $request): JsonResponse
    {
        $species = DeclarationRules::diagnosisSpecies((string) $request->validate(['species' => ['required', 'string', 'max:12']])['species']);

        return response()->json(['data' => IsabvCode::query()->ofKind(IsabvCode::KIND_DIAGNOSIS)->where('species_code', $species)->get()
            ->sort(fn ($a, $b) => strnatcmp($a->code, $b->code))->values()
            ->map(fn (IsabvCode $c) => ['code' => $c->code, 'parent' => $c->parent_code, 'label' => $c->label_fr, 'level' => IsabvCode::level($c->code)])]);
    }

    /** Les préparations à déclarer, cherchées par nom ou par code */
    public function preparations(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q']);
        $words = array_filter(preg_split('/\s+/', $q) ?: []);
        $found = IsabvPreparation::query()->where('must_transfer', true)
            ->where(function ($query) use ($q, $words): void {
                $query->where('code', 'like', $q.'%')->orWhere(function ($query) use ($words): void {
                    foreach ($words as $word) {
                        $query->where('name_fr', 'like', '%'.addcslashes($word, '%_\\').'%');
                    }
                });
            })
            ->orderBy('name_fr')->limit(30)->get();

        return response()->json(['data' => $found->map(fn (IsabvPreparation $p) => self::preparation($p))]);
    }

    /** @return array<string, mixed> */
    public static function preparation(IsabvPreparation $p): array
    {
        return [
            'code' => $p->code,
            'name' => $p->name_fr,
            'unit' => $p->unit_label_fr,
            'unit_code' => $p->unit_code,
            'route' => $p->route_code,
            'form' => $p->category_label_fr,
            'is_declarable' => $p->must_transfer,
            'has_critical_substance' => $p->has_critical_substance,
        ];
    }
}
