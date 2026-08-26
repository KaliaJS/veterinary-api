<?php

namespace App\Http\Resources\V1;

use App\Models\ListeAttente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListeAttente
 */
class ListeAttenteResource extends JsonResource
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
            'tel' => $this->tel,
            'reason' => $this->reason,
            'preference' => $this->preference,
            'ligne_planning_id' => $this->ligne_planning_id,
            'duree_minutes' => $this->duree_minutes,
            'inscrit_le' => $this->inscrit_le,
        ];
    }
}
