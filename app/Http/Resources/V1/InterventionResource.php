<?php

namespace App\Http\Resources\V1;

use App\Models\Intervention;
use App\Models\InterventionChecklistPoint;
use App\Models\InterventionTransition;
use App\Services\Messages\QuotaSms;
use App\Support\Telephone;
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
            // Comment il a été recueilli (02.11.2026) : signed, remote, oral, emergency
            'consent_method' => $this->consent_method,
            'consent_details' => $this->consent_details,
            'consent_collector' => $this->whenLoaded('consentCollector', fn (): ?array => $this->consentCollector ? [
                'id' => $this->consentCollector->id,
                'nom' => $this->consentCollector->nom,
            ] : null),
            // L'envoi pour signature en attente, s'il y en a un
            'consent_request' => $this->whenLoaded('latestConsentRequest', fn (): ?array => $this->latestConsentRequest?->isOpen() ? [
                'channels' => $this->latestConsentRequest->channels,
                'sent_at' => $this->latestConsentRequest->created_at,
                'expires_at' => $this->latestConsentRequest->expires_at,
            ] : null),
            'patient' => $this->whenLoaded('patient', fn (): array => [
                'id' => $this->patient->id,
                'nom' => $this->patient->nom,
                'espece' => $this->patient->espece,
                'proprietaire' => $this->patient->client->nom_affiche,
                // Où envoyer le consentement pour signature (02.11.2026)
                'contact' => [
                    'mobile' => Telephone::mobile($this->patient->client->tel),
                    'email' => $this->patient->client->email ?: null,
                    'has_space' => $this->patient->client->user_id !== null,
                    'sms_available' => QuotaSms::estConfigure(),
                ],
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
