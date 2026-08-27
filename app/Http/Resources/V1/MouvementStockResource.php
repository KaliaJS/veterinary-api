<?php

namespace App\Http\Resources\V1;

use App\Models\MouvementStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MouvementStock
 */
class MouvementStockResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'produit_id' => $this->produit_id,
            'clinique_id' => $this->clinique_id,
            'quantite' => $this->quantite,
            'libelle' => $this->libelle,
            'motif' => $this->motif,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'created_at' => $this->created_at,
        ];
    }
}
