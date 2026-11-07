<?php

namespace App\Http\Resources\V1;

use App\Models\Pesee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Pesee
 */
class PeseeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'poids_kg' => (float) $this->poids_kg,
            'pesee_le' => $this->pesee_le->toDateString(),
            // Qui a pesé l'animal (06.11.2026) ; null : le propriétaire, ou une pesée plus ancienne
            'employe_id' => $this->employe_id,
            'veterinaire' => $this->whenLoaded('employe', fn (): ?string => $this->employe?->nom),
        ];
    }
}
