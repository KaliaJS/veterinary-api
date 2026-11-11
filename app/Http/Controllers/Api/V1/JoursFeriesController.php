<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\ModifierJoursFeriesRequest;
use App\Models\Clinique;
use App\Services\Equipe\SoldesConges;
use App\Support\JoursFeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Les jours fériés de la clinique du jeton (Jim, 11.11.2026, É23) : ceux de
 * son canton (d'après son NPA, annexe du SECO), sa région, ses ajustements.
 * Lus par qui règle les horaires ou l'équipe ; changés avec le droit
 * Administration ou « Gérer l'équipe » (ils comptent dans les congés).
 */
class JoursFeriesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(Gate::any([DomainePermission::Planning->value, DomainePermission::Organisation->value, DomainePermission::Rh->value, DomainePermission::Equipe->value]), 403);
        $annee = (int) ($request->validate(['annee' => ['sometimes', 'integer', 'between:2000,2100']])['annee'] ?? aujourdhui()->year);

        return response()->json(['data' => self::etat($this->clinique(), $annee)]);
    }

    public function update(ModifierJoursFeriesRequest $request): JsonResponse
    {
        abort_unless(Gate::any([DomainePermission::Organisation->value, DomainePermission::Rh->value]), 403);
        $clinique = $this->clinique();
        $correspondance = ['variante' => 'jours_feries_variante', 'retires' => 'jours_feries_retires', 'ajoutes' => 'jours_feries_ajoutes'];
        foreach ($request->validated() as $cle => $valeur) {
            $clinique->{$correspondance[$cle]} = $valeur;
        }
        $clinique->save();

        // Une clinique relue : ses jours fériés se recalculent avec ses nouveaux choix
        return response()->json(['data' => self::etat($this->clinique(), aujourdhui()->year)]);
    }

    /** @return array{canton: string|null, variante: string|null, variantes: list<array{cle: string, nom: string}>, retires: list<string>, ajoutes: list<string>, jours: list<array<string, mixed>>} */
    public static function etat(Clinique $clinique, int $annee): array
    {
        $canton = $clinique->canton();
        $regions = JoursFeries::CANTONS[$canton ?? ''] ?? [];
        $variante = isset($regions[$clinique->jours_feries_variante ?? '']) ? $clinique->jours_feries_variante : array_key_first($regions);
        $duCanton = ['fete_nationale', ...JoursFeries::clesDuCanton($canton, $variante)];
        $actifs = collect($clinique->joursFeries($annee))->mapWithKeys(fn (array $jour): array => [$jour['cle'] => $jour['part']]);

        $jours = collect(JoursFeries::NOMS)
            ->map(fn (string $nom, string $cle): array => [
                'cle' => $cle,
                'nom' => $nom,
                'jour' => JoursFeries::date($cle, $annee)?->toDateString(),
                'part' => SoldesConges::nombre((float) ($actifs[$cle] ?? 1.0)),
                'est_actif' => $actifs->has($cle),
                'est_du_canton' => in_array($cle, $duCanton, true),
            ])
            ->sortBy('jour')
            ->values()
            ->all();

        return [
            'canton' => $canton,
            'variante' => $variante,
            'variantes' => collect($regions)->map(fn (array $region, string $cle): array => ['cle' => $cle, 'nom' => $region[0]])->values()->all(),
            // Ce que la clinique a choisi : les jours qu'elle travaille, ceux qu'elle chôme en plus
            'retires' => array_values($clinique->jours_feries_retires ?? []),
            'ajoutes' => array_values($clinique->jours_feries_ajoutes ?? []),
            'jours' => $jours,
        ];
    }

    private function clinique(): Clinique
    {
        return Clinique::query()->findOrFail(app(ContexteClinique::class)->cliniqueId());
    }
}
