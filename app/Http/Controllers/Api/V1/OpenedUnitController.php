<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MotifAjustementStock;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProduitResource;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Jeter l'unité entamée d'un article (troisième vérification, 03.11.2026) :
 * un flacon périmé ou souillé part avec son motif et une trace au journal,
 * même quand il ne reste aucun flacon fermé. Droit Stocks. L'article est
 * rendu comme par ProduitController : les stocks de toutes les cliniques.
 */
class OpenedUnitController extends Controller
{
    public function __construct(private GestionnaireStock $stock) {}

    public function discard(Request $request, Produit $produit): ProduitResource
    {
        $data = $request->validate([
            'motif' => ['required', Rule::in([MotifAjustementStock::Peremption->value, MotifAjustementStock::Casse->value])],
            'clinique_id' => ['sometimes', 'nullable', 'integer'],
        ], ['motif.*' => 'Périmé ou souillé : dites pourquoi le flacon entamé est jeté.']);

        $discarded = $this->stock->discardOpened($produit, MotifAjustementStock::from($data['motif']), $request->user(), $data['clinique_id'] ?? null);
        if ($discarded <= 0) {
            throw ValidationException::withMessages(['produit' => 'Aucune unité entamée à jeter dans cette clinique.']);
        }

        return $this->resource($produit);
    }

    /**
     * L'inventaire du flacon entamé (quatrième vérification, 03.11.2026) :
     * le reste compté remplace le reste noté, l'écart part au journal. Sans
     * reste entamé, il en déclare un (cinquième vérification).
     */
    public function count(Request $request, Produit $produit): ProduitResource
    {
        $content = (float) $produit->content_per_unit;
        if ($content <= 0 || $produit->dose_unit === null) {
            throw ValidationException::withMessages(['opened_rest' => 'La contenance de l’article n’est pas connue : renseignez-la d’abord.']);
        }
        // Seulement dans l'unité de l'article (cinquième vérification) : un reste
        // compté dans une autre unité ne se convertit pas
        $data = $request->validate([
            'opened_rest' => ['required', 'numeric', 'min:0', 'max:'.$content],
            'dose_unit' => ['required', Rule::in([$produit->dose_unit])],
            'clinique_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'opened_rest.max' => 'Le reste entamé ne dépasse pas la contenance d’une unité.',
            'dose_unit.*' => 'Le reste se compte en '.$produit->dose_unit.', l’unité de l’article.',
        ]);
        // Reste maximal et unité revérifiés sous le verrou de l'article (dixième vérification)
        $gap = $this->stock->countOpened($produit, (float) $data['opened_rest'], $request->user(), $data['clinique_id'] ?? null, $data['dose_unit']);

        // L'écart dit à l'écran s'il y a eu un changement (rien : rien à dire)
        return $this->resource($produit)->additional(['meta' => ['gap' => $gap]]);
    }

    /** L'article comme ProduitController le rend : les stocks de toutes les cliniques */
    private function resource(Produit $produit): ProduitResource
    {
        return new ProduitResource($produit->load(['stocks' => fn ($q) => $q->withoutGlobalScope('clinique'), 'vetpoint']));
    }
}
