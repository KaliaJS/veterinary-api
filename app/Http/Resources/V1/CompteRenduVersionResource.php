<?php

namespace App\Http\Resources\V1;

use App\Models\CompteRenduVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompteRenduVersion
 */
class CompteRenduVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            's' => $this->s,
            'o' => $this->o,
            'a' => $this->a,
            'p' => $this->p,
            'auteur' => $this->user?->name,
            'redigee_le' => $this->redigee_le,
            'remplacee_le' => $this->created_at,
        ];
    }
}
