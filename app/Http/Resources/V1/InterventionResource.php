<?php

namespace App\Http\Resources\V1;

use App\Models\Intervention;
use App\Models\InterventionChecklistPoint;
use App\Models\InterventionTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Intervention
 */
class InterventionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique_id' => $this->clinique_id,
            'patient_id' => $this->patient_id,
            'salle_id' => $this->salle_id,
            'salle_nom' => $this->salle_nom,
            'chirurgien_id' => $this->chirurgien_id,
            'rendez_vous_id' => $this->rendez_vous_id,
            'procedure' => $this->procedure,
            'prevu_le' => $this->prevu_le,
            'duree_minutes' => $this->duree_minutes,
            'statut' => $this->statut,
            'protocole_anesthesique' => $this->protocole_anesthesique,
            'note_operatoire' => $this->note_operatoire,
            'incision_a' => $this->incision_a,
            'consentement_signe_le' => $this->consentement_signe_le,
            'consentement_document_id' => $this->consentement_document_id,
            'patient' => $this->whenLoaded('patient', fn (): array => [
                'id' => $this->patient->id,
                'nom' => $this->patient->nom,
                'espece' => $this->patient->espece,
                'proprietaire' => $this->patient->client->nom_affiche,
            ]),
            'chirurgien' => $this->whenLoaded('chirurgien', fn (): array => [
                'id' => $this->chirurgien->id,
                'nom' => $this->chirurgien->nom,
            ]),
            'checklist' => $this->whenLoaded('checklist', fn () => $this->checklist->map(fn (InterventionChecklistPoint $point): array => [
                'code' => $point->code,
                'libelle' => $point->libelle,
                'est_coche' => $point->est_coche,
                'est_ajoute' => $point->est_ajoute,
            ])),
            'transitions' => $this->whenLoaded('transitions', fn () => $this->transitions->map(fn (InterventionTransition $transition): array => [
                'statut' => $transition->statut,
                'at' => $transition->created_at,
            ])),
        ];
    }
}
