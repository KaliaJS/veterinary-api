<?php

namespace App\Http\Resources\V1;

use App\Models\Lot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lot
 */
class LotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'produit_id' => $this->produit_id,
            'clinique_id' => $this->clinique_id,
            'numero_lot' => $this->numero_lot,
            'peremption' => $this->peremptionAffichee(),
            'quantite' => $this->quantite,
        ];
    }
}
