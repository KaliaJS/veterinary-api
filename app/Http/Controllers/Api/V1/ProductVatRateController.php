<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategorieProduit;
use App\Enums\VatRate;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProduitResource;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le taux de TVA à la vente d'un article (TVA par ligne, 03.11.2026) :
 * proposé selon la famille, corrigible par article. Il vaut pour ce qui
 * est remis au propriétaire ou vendu ; ce qui est donné pendant un soin
 * suit le soin. Les factures déjà émises gardent le leur. Droit Stocks.
 */
class ProductVatRateController extends Controller
{
    /** Les taux en vigueur et la proposition par famille, pour la fenêtre de l'article. */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Produit::class);

        return response()->json(['data' => [
            'rates' => collect(VatRate::cases())->mapWithKeys(fn (VatRate $rate): array => [$rate->value => $rate->percent()])->all(),
            'category_defaults' => collect(CategorieProduit::cases())->mapWithKeys(fn (CategorieProduit $categorie): array => [$categorie->value => VatRate::forCategory($categorie)->value])->all(),
        ]]);
    }

    public function update(Request $request, Produit $produit): ProduitResource
    {
        $this->authorize('update', $produit);

        $produit->update($request->validate(['vat_rate' => ['required', Rule::enum(VatRate::class)]]));

        return new ProduitResource($produit->load(['stocks' => fn ($q) => $q->withoutGlobalScope('clinique'), 'vetpoint']));
    }
}
