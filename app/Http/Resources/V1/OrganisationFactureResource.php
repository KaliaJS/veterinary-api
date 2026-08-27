<?php

namespace App\Http\Resources\V1;

use App\Models\OrganisationFacture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganisationFacture
 */
class OrganisationFactureResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'emise_le' => $this->emise_le->toDateString(),
            'libelle' => $this->libelle,
            'montant' => (float) $this->montant,
        ];
    }
}
