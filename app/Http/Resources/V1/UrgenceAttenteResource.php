<?php

namespace App\Http\Resources\V1;

use App\Models\UrgenceAttente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UrgenceAttente
 */
class UrgenceAttenteResource extends JsonResource
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
            'triage' => $this->triage,
            'duree_minutes' => $this->duree_minutes,
            'arrivee_le' => $this->arrivee_le,
        ];
    }
}
