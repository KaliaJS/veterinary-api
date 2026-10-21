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
            // Demande sans compte : de quoi répondre au propriétaire
            'email' => $this->email,
            'telephone' => $this->telephone,
            'reason' => $this->reason,
            'urgence' => $this->urgence,
            'symptomes' => $this->symptomes,
            'souhait' => $this->souhait,
            'mode' => $this->mode,
            'duree_minutes' => $this->duree_minutes,
        ];
    }
}
