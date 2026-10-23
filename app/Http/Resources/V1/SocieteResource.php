<?php

namespace App\Http\Resources\V1;

use App\Models\Societe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Societe
 */
class SocieteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'ide' => $this->ide,
            'assujettie_tva' => $this->assujettie_tva !== false,
            'nombre_cliniques' => $this->whenCounted('cliniques'),
        ];
    }
}
