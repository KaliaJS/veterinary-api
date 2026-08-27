<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MouvementStockResource;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Journal des mouvements d'un produit. Lecture transversale à
 * l'organisation (l'écran affiche les gestes de toutes les cliniques) :
 * le scope clinique est levé, borné par le produit — lui-même scopé
 * organisation. Les écritures, elles, restent l'affaire du
 * GestionnaireStock, clinique du jeton uniquement.
 */
class MouvementStockController extends Controller
{
    public function index(Request $request, Produit $produit): AnonymousResourceCollection
    {
        $this->authorize('view', $produit);

        $mouvements = $produit->mouvements()
            ->withoutGlobalScope('clinique')
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->parPage($request));

        return MouvementStockResource::collection($mouvements);
    }
}
