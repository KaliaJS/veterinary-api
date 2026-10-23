<?php

namespace App\Http\Resources\V1\Espace;

use App\Models\DemandeRendezVous;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une demande de rendez-vous vue par son propriétaire (espace client ou
 * lien sans compte) : ce qu'il a demandé et la réponse de la clinique.
 *
 * @mixin DemandeRendezVous
 */
class DemandeProprietaireResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique' => [
                'id' => $this->clinique->id,
                'nom' => $this->clinique->nom,
                'ville' => $this->clinique->ville,
                'tel' => $this->clinique->tel,
                'fuseau_horaire' => $this->clinique->fuseau_horaire ?? 'Europe/Zurich',
            ],
            'patient_name' => $this->patient_name,
            'species' => $this->species,
            'reason' => $this->reason,
            'urgence' => $this->urgence,
            'teleconsultation' => $this->teleconsultation,
            'debut_souhaite' => $this->debut_souhaite?->toIso8601String(),
            'statut' => $this->statut,
            'creneaux_proposes' => $this->creneaux_proposes ?? [],
            'motif_refus' => $this->motif_refus?->libelle(),
            'message' => $this->message,
            'rendez_vous' => $this->rendezVous ? ['id' => $this->rendezVous->id, 'starts_at' => $this->rendezVous->starts_at->toIso8601String()] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'traitee_le' => $this->traitee_le?->toIso8601String(),
            // Acompte de la réservation : payé, rendu (jamais d'office)
            'acompte' => $this->acompte_centimes ? [
                'montant' => round($this->acompte_centimes / 100, 2),
                'paye' => $this->acompte_paye_le !== null,
                'rembourse' => $this->acompte_rembourse_le !== null,
            ] : null,
        ];
    }
}
