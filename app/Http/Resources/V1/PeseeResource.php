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
        ];
    }
}
