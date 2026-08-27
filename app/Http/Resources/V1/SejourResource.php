<?php

namespace App\Http\Resources\V1;

use App\Models\Sejour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sejour
 */
class SejourResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique_id' => $this->clinique_id,
            'patient_id' => $this->patient_id,
            'box' => $this->box,
            'motif' => $this->motif,
            'statut' => $this->statut,
            'veterinaire_id' => $this->veterinaire_id,
            'admis_le' => $this->admis_le,
            'sortie_prevue' => $this->sortie_prevue,
            'sorti_le' => $this->sorti_le,
            'perfusion' => $this->perfusion,
            'note' => $this->note,
            'facture_id' => $this->facture_id,
            'patient' => $this->whenLoaded('patient', fn (): array => [
                'id' => $this->patient->id,
                'nom' => $this->patient->nom,
                'espece' => $this->patient->espece,
                'proprietaire' => $this->patient->client->nom_affiche,
            ]),
            'veterinaire' => $this->whenLoaded('veterinaire', fn (): ?array => $this->veterinaire === null ? null : [
                'id' => $this->veterinaire->id,
                'nom' => $this->veterinaire->nom,
            ]),
            'soins' => SejourSoinResource::collection($this->whenLoaded('soins')),
            'constantes' => SejourConstanteResource::collection($this->whenLoaded('constantes')),
        ];
    }
}
