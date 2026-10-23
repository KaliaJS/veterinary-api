<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Boutique\ModifierVitrineRequest;
use App\Http\Resources\V1\ProduitResource;
use App\Models\Produit;
use Illuminate\Validation\ValidationException;

/**
 * Vitrine en ligne : visibilité boutique, promotion, produit vedette —
 * des champs du produit (docs/data-model.md §14), catalogue
 * d'organisation.
 */
class VitrineController extends Controller
{
    public function modifier(ModifierVitrineRequest $request, Produit $produit): ProduitResource
    {
        $this->authorize('update', $produit);

        // Un stupéfiant ne s'affiche jamais en boutique (23.10.2026)
        if ($produit->est_stupefiant && $request->boolean('est_boutique')) {
            throw ValidationException::withMessages(['est_boutique' => 'Un stupéfiant ne s’affiche pas en boutique.']);
        }

        $produit->update($request->validated());

        return new ProduitResource(
            $produit->load(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique')]),
        );
    }
}
