<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MotifAjustementStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stocks\AjusterStockRequest;
use App\Http\Resources\V1\ProduitResource;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use Illuminate\Validation\ValidationException;

/**
 * Ajustement manuel motivé du stock d'une clinique de l'organisation —
 * celle du jeton par défaut, une clinique sœur sur demande (l'écran
 * règle toute la ventilation) (docs/data-model.md §14). Une sortie sur
 * un stock déjà à zéro ne laisse aucune trace — elle est refusée,
 * comme au front.
 */
class AjustementStockController extends Controller
{
    public function __invoke(AjusterStockRequest $request, Produit $produit, GestionnaireStock $stock): ProduitResource
    {
        $this->authorize('update', $produit);

        // Le stock d'un stupéfiant suit son registre (24.10.2026)
        if ($produit->est_stupefiant) {
            throw ValidationException::withMessages([
                'quantite' => 'Le stock d’un stupéfiant suit son registre : inscrivez-y la réception ou la sortie (Outils, Registre des stupéfiants).',
            ]);
        }

        $motif = MotifAjustementStock::from($request->validated('motif'));

        $reel = $stock->appliquer(
            $produit,
            (int) $request->validated('quantite'),
            $motif->libelle(),
            $request->user(),
            $motif,
            cliniqueId: $request->cliniqueId(),
        );

        if ($reel === 0) {
            throw ValidationException::withMessages([
                'quantite' => 'Stock déjà à zéro : aucune sortie possible.',
            ]);
        }

        return new ProduitResource(
            $produit->load(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique')]),
        );
    }
}
