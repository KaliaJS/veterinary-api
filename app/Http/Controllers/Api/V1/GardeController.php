<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\ModifierGardeRequest;
use App\Http\Requests\V1\Equipe\PeriodeEquipeRequest;
use App\Models\Employe;
use App\Models\Garde;
use App\Services\RotationGardes;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Les nuits de garde de la clinique du jeton, lues par toute l'équipe
 * (le planning les montre à chacun) ; les choisir demande le droit
 * Équipe. Un choix du samedi ou du dimanche vaut pour le week-end.
 */
class GardeController extends Controller
{
    // Le calcul se fait à chaque requête (injecté par méthode) : le contrôleur peut survivre d'une requête à l'autre
    public function index(PeriodeEquipeRequest $request, RotationGardes $rotation): JsonResponse
    {
        $du = CarbonImmutable::parse($request->validated('du'));
        $au = CarbonImmutable::parse($request->validated('au'));

        return $this->reponse($rotation, $du, $au);
    }

    public function modifier(ModifierGardeRequest $request, RotationGardes $rotation): JsonResponse
    {
        $this->authorize('organiserGardes', Employe::class);

        $jour = CarbonImmutable::parse($request->validated('jour'));
        $employeId = $request->validated('employe_id');

        foreach ($request->nuits() as $nuit) {
            // Une nuit passée corrigée entre au registre (É28) ; une nuit à venir reste un choix, vérifié jusqu'à son enregistrement
            $valeurs = [
                'employe_id' => $employeId,
                'est_choisie' => true,
                'enregistree_le' => $nuit->lt(aujourdhui()) ? now() : null,
                'choisie_par_user_id' => $request->user()->id,
            ];
            // Cherchée par le jour (la colonne peut garder une heure selon la base) et tenue le temps de l'écrire :
            // le passage du matin attend, la correction l'emporte
            DB::transaction(function () use ($nuit, $employeId, $valeurs): void {
                $garde = Garde::query()->whereDate('jour', $nuit)->lockForUpdate()->first();
                if ($employeId === null) {
                    $garde?->delete();
                } elseif ($garde !== null) {
                    $garde->forceFill($valeurs)->save();
                } else {
                    try {
                        Garde::query()->create(['jour' => $nuit->toDateString(), ...$valeurs]);
                    } catch (UniqueConstraintViolationException) {
                        // Écrite au même instant par le passage du matin
                        Garde::query()->whereDate('jour', $nuit)->firstOrFail()->forceFill($valeurs)->save();
                    }
                }
            });
        }

        return $this->reponse($rotation, $jour->startOfWeek(CarbonImmutable::MONDAY), $jour->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay());
    }

    private function reponse(RotationGardes $rotation, CarbonImmutable $du, CarbonImmutable $au): JsonResponse
    {
        return response()->json([
            'data' => $rotation->nuits($du, $au),
            'meta' => [
                'maximum' => RotationGardes::MAXIMUM_QUATRE_SEMAINES,
                'compteurs' => $rotation->compteurs($du, $au),
            ],
        ]);
    }
}
