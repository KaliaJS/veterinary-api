<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProduitResource;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use Illuminate\Http\Request;

/**
 * Ce que contient une unité d'un article (médicaments à la dose,
 * 03.11.2026) : 20 ml dans un flacon, 20 cp dans une boîte. Chaque prise
 * d'hospitalisation sort sa dose de l'unité entamée. Droit Stocks.
 */
class ProductContentController extends Controller
{
    public function __construct(private GestionnaireStock $stock) {}

    public function update(Request $request, Produit $produit): ProduitResource
    {
        $data = $request->validate([
            'content_per_unit' => ['present', 'nullable', 'numeric', 'gt:0', 'max:100000'],
            'dose_unit' => ['required_with:content_per_unit', 'nullable', 'string', 'max:12'],
        ], [
            'dose_unit.required_with' => 'L’unité de dose (ml, cp, UI…) accompagne la contenance.',
        ]);

        // L'article d'abord, puis ses stocks : le même ordre partout (dixième vérification) ;
        // le flacon entamé suit, ramené à la contenance ou remis à zéro avec une trace
        $this->stock->changeContent(
            $produit,
            $data['content_per_unit'] === null ? null : (float) $data['content_per_unit'],
            $data['content_per_unit'] === null ? null : trim((string) $data['dose_unit']),
            $request->user(),
        );

        return new ProduitResource($produit->load(['stocks' => fn ($q) => $q->withoutGlobalScope('clinique'), 'vetpoint']));
    }
}
