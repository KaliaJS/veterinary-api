<?php

namespace App\Http\Resources\V1;

use App\Models\SessionTeleconsultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SessionTeleconsultation
 */
class SessionTeleconsultationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique_id' => $this->clinique_id,
            'rendez_vous_id' => $this->rendez_vous_id,
            'patient_id' => $this->patient_id,
            'motif' => $this->motif,
            'demarre_le' => $this->demarre_le,
            'terminee_le' => $this->terminee_le,
            'est_terminee' => $this->estTerminee(),
            'duree_secondes' => $this->duree_secondes,
            // Durée affichée du front : au moins une minute.
            'duree_minutes' => $this->duree_secondes === null ? null : max(1, (int) round($this->duree_secondes / 60)),
            'cloture_rendez_vous' => $this->cloture_rendez_vous,
            'patient' => $this->whenLoaded('patient', fn (): ?array => $this->patient === null ? null : [
                'id' => $this->patient->id,
                'nom' => $this->patient->nom,
                'espece' => $this->patient->espece,
                'proprietaire' => $this->patient->client->nom_affiche,
            ]),
            'demarre_par' => $this->whenLoaded('demarrePar', fn (): array => [
                'id' => $this->demarrePar->id,
                'nom' => $this->demarrePar->nom,
            ]),
        ];
    }
}
