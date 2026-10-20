<?php

namespace App\Http\Resources\V1;

use App\Models\Prestation;
use App\Services\PrestationsParDefaut;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Prestation
 */
class PrestationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'prix' => (float) $this->prix,
            // Acte de départ, lu par son code : il ne se supprime pas
            'systeme' => array_key_exists($this->code, PrestationsParDefaut::CATALOGUE),
        ];
    }
}
