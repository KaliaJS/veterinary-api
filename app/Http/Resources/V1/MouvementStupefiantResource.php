<?php

namespace App\Http\Resources\V1;

use App\Models\MouvementStupefiant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MouvementStupefiant
 */
class MouvementStupefiantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique_id' => $this->clinique_id,
            'produit_id' => $this->produit_id,
            'patient_id' => $this->patient_id,
            'employe_id' => $this->employe_id,
            'quantite' => $this->quantite,
            'motif' => $this->motif,
            'created_at' => $this->created_at,
            'produit' => $this->whenLoaded('produit', fn (): array => [
                'id' => $this->produit->id,
                'nom' => $this->produit->nom,
                'unite' => $this->produit->unite,
            ]),
            'patient' => $this->whenLoaded('patient', fn (): ?array => $this->patient === null ? null : [
                'id' => $this->patient->id,
                'nom' => $this->patient->nom,
                'espece' => $this->patient->espece,
            ]),
            'signataire' => $this->whenLoaded('employe', fn (): ?array => $this->employe === null ? null : [
                'id' => $this->employe->id,
                'nom' => $this->employe->nom,
            ]),
        ];
    }
}
