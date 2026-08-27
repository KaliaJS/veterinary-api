<?php

namespace App\Http\Resources\V1;

use App\Models\SejourSoin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SejourSoin
 */
class SejourSoinResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sejour_id' => $this->sejour_id,
            'prevu_a' => $this->prevu_a,
            'acte' => $this->acte,
            'produit_id' => $this->produit_id,
            'fait' => $this->fait,
            'fait_le' => $this->fait_le,
            'produit' => $this->whenLoaded('produit', fn (): ?array => $this->produit === null ? null : [
                'id' => $this->produit->id,
                'nom' => $this->produit->nom,
            ]),
        ];
    }
}
