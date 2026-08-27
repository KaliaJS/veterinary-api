<?php

namespace App\Http\Resources\V1;

use App\Models\DecompteAssurance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DecompteAssurance
 */
class DecompteAssuranceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'facture_id' => $this->facture_id,
            'assureur' => $this->assureur,
            'statut' => $this->statut,
            'envoye_le' => $this->envoye_le,
            'rembourse_le' => $this->rembourse_le,
        ];
    }
}
