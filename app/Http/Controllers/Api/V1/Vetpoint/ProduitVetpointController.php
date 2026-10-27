<?php

namespace App\Http\Controllers\Api\V1\Vetpoint;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProduitResource;
use App\Models\Produit;
use App\Models\ProduitVetpoint;
use Illuminate\Http\Request;

/**
 * Relier un produit du catalogue à un produit Vetpoint, ou le délier
 * (27.10.2026). Droit « Stocks » ; le produit est résolu dans l'organisation
 * du jeton.
 */
class ProduitVetpointController extends Controller
{
    public function store(Request $request, Produit $produit): ProduitResource
    {
        $donnees = $request->validate([
            'vetpoint_id' => ['required', 'string', 'max:40'],
            'nom' => ['required', 'string', 'max:190'],
            'swissmedic' => ['nullable', 'string', 'max:20'],
        ]);

        ProduitVetpoint::query()->updateOrCreate(['produit_id' => $produit->id], $donnees);

        return $this->fiche($produit);
    }

    public function destroy(Produit $produit): ProduitResource
    {
        $produit->vetpoint()->delete();

        return $this->fiche($produit);
    }

    private function fiche(Produit $produit): ProduitResource
    {
        return new ProduitResource($produit->load(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique'), 'vetpoint']));
    }
}
