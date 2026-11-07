<?php

namespace App\Http\Resources\V1;

use App\Models\LotAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une sortie qui a pris dans un lot (04.11.2026) : combien, nette de ce
 * qui a été rendu, quand, par quel geste, à quel animal et à quel client.
 *
 * @mixin LotAllocation
 */
class LotTakeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_lot' => $this->lot_number,
            'quantite' => -$this->quantity - (int) $this->returned,
            'libelle' => $this->mouvement?->libelle,
            'source_type' => $this->mouvement?->source_type,
            'source_id' => $this->mouvement?->source_id,
            'patient' => $this->patient === null ? null : ['id' => $this->patient->id, 'nom' => $this->patient->nom],
            'client' => $this->client === null ? null : ['id' => $this->client->id, 'nom' => $this->client->nom_affiche],
            'created_at' => $this->created_at,
        ];
    }
}
