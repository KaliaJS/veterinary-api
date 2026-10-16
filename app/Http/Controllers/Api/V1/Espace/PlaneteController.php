<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\ArticlePlanete;
use App\Http\Controllers\Controller;
use App\Models\AchatPlanete;
use App\Models\Patient;
use App\Models\User;
use App\Services\Abonnements\AbonnementSaas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jeu de la planète de l'espace client (16.10.2026) : la boutique (objets
 * achetés une fois, prélevés sur la carte du compte, posés pour toujours)
 * et le décès d'un animal (une petite tombe à son nom sur la planète ; les
 * rappels de la clinique s'arrêtent). Le décès se déclare pour tout animal
 * du compte, fiche de clinique comprise : la clinique le voit aussi.
 */
class PlaneteController extends Controller
{
    public function __construct(private AbonnementSaas $abonnements) {}

    public function afficher(ContexteProprietaire $contexte): JsonResponse
    {
        return $this->etat($contexte->user());
    }

    public function acheter(Request $request, ContexteProprietaire $contexte): JsonResponse
    {
        $donnees = $request->validate(['article' => ['required', Rule::enum(ArticlePlanete::class)]]);
        $this->abonnements->acheterArticle($contexte->user(), ArticlePlanete::from($donnees['article']));

        return $this->etat($contexte->user());
    }

    public function declarerDeces(Request $request, ContexteProprietaire $contexte, int $animalId): JsonResponse
    {
        $donnees = $request->validate(['decede_le' => ['nullable', 'date', 'before_or_equal:today']], [
            'decede_le.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);
        $animal = $contexte->filtrerAnimaux(Patient::withoutGlobalScopes())->findOrFail($animalId);
        $animal->forceFill(['decede_le' => $donnees['decede_le'] ?? today()->toDateString(), 'rappels_coupes' => true])->save();

        return response()->json(['data' => ['id' => $animal->id, 'decede_le' => $animal->decede_le->toDateString()]]);
    }

    public function annulerDeces(ContexteProprietaire $contexte, int $animalId): JsonResponse
    {
        $animal = $contexte->filtrerAnimaux(Patient::withoutGlobalScopes())->findOrFail($animalId);
        $animal->forceFill(['decede_le' => null])->save();

        return response()->json(['data' => ['id' => $animal->id, 'decede_le' => null]]);
    }

    private function etat(User $user): JsonResponse
    {
        $possedes = AchatPlanete::query()->where('user_id', $user->id)->pluck('article')->map(fn (ArticlePlanete $a): string => $a->value)->all();

        return response()->json(['data' => [
            'articles' => array_map(fn (ArticlePlanete $a): array => [
                'cle' => $a->value,
                'nom' => $a->nom(),
                'description' => $a->description(),
                'prix' => $a->centimes() / 100,
                'possede' => in_array($a->value, $possedes, true),
            ], ArticlePlanete::cases()),
            'possedes' => $possedes,
            'moyen_paiement' => $user->moyen_paiement,
        ]]);
    }
}
