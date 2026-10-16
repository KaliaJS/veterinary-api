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
            'statut' => $this->statut,
            'montant' => (float) $this->montant,
            'montant_ht' => $this->montant_ht === null ? null : (float) $this->montant_ht,
            'tva' => $this->tva === null ? null : (float) $this->tva,
            'pdf_url' => $this->pdf_url,
            'paiement_url' => $this->paiement_url,
        ];
    }
}
