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
            // L'heure choisie dans le calendrier (UTC), sur place ou en vidéo
            'debut_souhaite' => $this->debut_souhaite?->toIso8601String(),
            'teleconsultation' => $this->teleconsultation,
            'mode' => $this->mode,
            'duree_minutes' => $this->duree_minutes,
            // La réponse de la clinique
            'statut' => $this->statut,
            'creneaux_proposes' => $this->creneaux_proposes ?? [],
            'motif_refus' => $this->motif_refus,
            'message' => $this->message,
            'rendez_vous_id' => $this->rendez_vous_id,
            'rendez_vous_debut' => $this->whenLoaded('rendezVous', fn () => $this->rendezVous?->starts_at->toIso8601String()),
            // Les réponses lui sont écrites (compte ou adresse laissée) ; sinon, l'appeler
            'prevenu_par_email' => $this->adresseDuProprietaire() !== null,
            // Acompte payé et gardé (francs), déduit de la facture du rendez-vous
            'acompte' => $this->acompteRetenu(),
            'traitee_le' => $this->traitee_le?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
