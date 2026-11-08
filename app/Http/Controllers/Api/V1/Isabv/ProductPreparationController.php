<?php

namespace App\Http\Controllers\Api\V1\Isabv;

use App\Enums\CategorieProduit;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProduitResource;
use App\Models\IsabvPreparation;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Relie à la main un médicament du stock à une préparation de la liste de
 * l'OSAV, quand son code-barres ne la donne pas (médicament humain,
 * importé, article sans code-barres) ; null revient au code-barres
 * (08.11.2026). Droit Stocks.
 */
class ProductPreparationController extends Controller
{
    public function update(Request $request, Produit $produit): ProduitResource
    {
        if ($produit->categorie !== CategorieProduit::Medicament) {
            throw ValidationException::withMessages(['preparation_code' => 'Seul un médicament se relie à une préparation de l’OSAV.']);
        }
        $code = $request->validate([
            // Seulement une préparation à déclarer : un lien à la main ne rend jamais un antibiotique non déclaré
            'preparation_code' => ['present', 'nullable', 'string', Rule::exists(IsabvPreparation::class, 'code')->where('must_transfer', true)],
        ], ['preparation_code.exists' => 'Cette préparation n’est pas dans la liste des antibiotiques à déclarer de l’OSAV.'])['preparation_code'];
        $produit->update(['isabv_preparation_code' => $code]);

        return new ProduitResource($produit->fresh()->load(['stocks' => fn ($q) => $q->withoutGlobalScope('clinique'), 'vetpoint']));
    }
}
