<?php

namespace App\Http\Resources\V1;

use App\Models\DemandeRendezVous;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DemandeRendezVous
 */
class DemandeRendezVousResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'client_id' => $this->client_id,
            'patient_name' => $this->patient_name,
            'species' => $this->species,
            'owner_name' => $this->owner_name,
            'reason' => $this->reason,
            'souhait' => $this->souhait,
            'mode' => $this->mode,
            'duree_minutes' => $this->duree_minutes,
        ];
    }
}
