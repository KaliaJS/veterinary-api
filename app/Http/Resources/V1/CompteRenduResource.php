<?php

namespace App\Http\Resources\V1;

use App\Models\CompteRendu;
use App\Models\CompteRenduComplement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompteRendu
 */
class CompteRenduResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rendez_vous_id' => $this->rendez_vous_id,
            's' => $this->s,
            'o' => $this->o,
            'a' => $this->a,
            'p' => $this->p,
            'user_id' => $this->user_id,
            'redige_le' => $this->updated_at,
            'signe_le' => $this->signe_le,
            'signe_par' => $this->signataire?->user?->name,
            'complements' => $this->whenLoaded('complements', fn () => $this->complements->map(fn (CompteRenduComplement $c) => [
                'id' => $c->id,
                'texte' => $c->texte,
                'signe_par' => $c->signataire?->user?->name,
                'signe_le' => $c->created_at,
            ])->values()),
            'versions' => $this->whenCounted('versions'),
        ];
    }
}
