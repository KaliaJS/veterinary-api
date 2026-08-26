<?php

namespace App\Http\Resources\V1;

use App\Models\RendezVous;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RendezVous
 */
class RendezVousResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ligne_planning_id' => $this->ligne_planning_id,
            'salle_id' => $this->salle_id,
            'patient_id' => $this->patient_id,
            'client_id' => $this->client_id,
            'patient_name' => $this->patient_name,
            'species' => $this->species,
            'owner_name' => $this->owner_name,
            'type' => $this->type,
            'status' => $this->status,
            'flags' => $this->flags,
            'reason' => $this->reason,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'arrived_at' => $this->arrived_at,
        ];
    }
}
