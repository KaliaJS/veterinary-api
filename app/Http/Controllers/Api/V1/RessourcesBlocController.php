<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TypeSalle;
use App\Http\Controllers\Controller;
use App\Models\Employe;
use App\Models\Intervention;
use App\Models\Salle;
use Illuminate\Http\JsonResponse;

/**
 * Ce qu'il faut pour programmer une intervention (23.10.2026) : les
 * salles de bloc et les vétérinaires de la clinique du jeton, sous le
 * seul droit bloc (sans celui du planning ni de l'équipe).
 */
class RessourcesBlocController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Intervention::class);

        $salles = Salle::query()
            ->where('type', TypeSalle::Bloc)
            ->orderBy('nom')
            ->get()
            ->map(fn (Salle $salle): array => ['id' => $salle->id, 'nom' => $salle->nom])
            ->values();

        $chirurgiens = Employe::query()
            ->orderBy('nom')
            ->get()
            ->filter(fn (Employe $employe): bool => $employe->estVeterinaire() && $employe->estPresentable())
            ->map(fn (Employe $employe): array => ['id' => $employe->id, 'nom' => $employe->nom])
            ->values();

        return response()->json(['data' => ['salles' => $salles, 'chirurgiens' => $chirurgiens]]);
    }
}
