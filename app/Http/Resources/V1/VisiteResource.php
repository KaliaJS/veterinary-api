<?php

namespace App\Http\Resources\V1;

use App\Models\Visite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Visite
 */
class VisiteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visite_le' => $this->visite_le->toDateString(),
            'motif' => $this->motif,
            'employe_id' => $this->employe_id,
            'veterinaire' => $this->whenLoaded('employe', fn () => $this->employe?->nom),
            'note' => $this->note,
        ];
    }
}
