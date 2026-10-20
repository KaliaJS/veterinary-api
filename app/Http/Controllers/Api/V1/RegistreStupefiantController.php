<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MouvementStupefiantResource;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Soldes et export du registre des stupéfiants — par clinique, celle
 * du jeton. Solde d'une substance = Σ des mouvements (le solde de
 * départ du front est un mouvement de report) ; « STOCK BAS » sous
 * MouvementStupefiant::SEUIL_STOCK_BAS, parité front. L'export sert le
 * PDF des contrôles cantonaux, généré au front comme les autres pièces.
 */
class RegistreStupefiantController extends Controller
{
    public function soldes(): JsonResponse
    {
        $this->authorize('viewAny', MouvementStupefiant::class);

        return response()->json(['data' => $this->calculerSoldes()]);
    }

    public function exporter(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MouvementStupefiant::class);

        $periode = $request->validate([
            'du' => ['sometimes', 'date'],
            'au' => ['sometimes', 'date', 'after_or_equal:du'],
        ]);

        $mouvements = MouvementStupefiant::query()
            ->with(['produit', 'patient', 'employe' => fn ($requete) => $requete->withoutGlobalScope('clinique')])
            ->when($request->date('du'), fn ($requete, $du) => $requete->where('created_at', '>=', $du))
            ->when($request->date('au'), fn ($requete, $au) => $requete->where('created_at', '<=', $au->endOfDay()))
            ->latest('created_at')
            ->latest('id')
            ->get();

        return response()->json(['data' => [
            'etabli_le' => now(),
            'periode' => ['du' => $periode['du'] ?? null, 'au' => $periode['au'] ?? null],
            'soldes' => $this->calculerSoldes(),
            'mouvements' => MouvementStupefiantResource::collection($mouvements),
        ]]);
    }

    /**
     * Soldes courants de toutes les substances du catalogue pour la
     * clinique du jeton (le scope clinique borne la somme).
     *
     * @return list<array<string, mixed>>
     */
    private function calculerSoldes(): array
    {
        return Produit::query()
            ->where('est_stupefiant', true)
            ->withSum('mouvementsStupefiants as solde', 'quantite')
            ->orderBy('nom')
            ->get()
            ->map(fn (Produit $produit): array => [
                'produit_id' => $produit->id,
                'nom' => $produit->nom,
                'unite' => $produit->unite,
                'solde' => round((float) $produit->solde, 2),
                'stock_bas' => (float) $produit->solde < MouvementStupefiant::SEUIL_STOCK_BAS,
            ])
            ->all();
    }
}
