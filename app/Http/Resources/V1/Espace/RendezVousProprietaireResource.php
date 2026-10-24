<?php

namespace App\Http\Resources\V1\Espace;

use App\Enums\StatutRendezVous;
use App\Models\RendezVous;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rendez-vous vu par le propriétaire (« Mes rendez-vous », accueil) :
 * ni no-shows ni compte rendu — ce sont des données de la clinique.
 * `peut_annuler` / `peut_reporter` : prévu et encore à venir. Avec un
 * acompte payé, on n'annule plus soi-même : `peut_demander_annulation`,
 * la clinique décide (23.10.2026). `report_demande` : un report attend
 * déjà la réponse de la clinique, on n'en redemande pas (24.10.2026).
 *
 * @mixin RendezVous
 */
class RendezVousProprietaireResource extends JsonResource
{
    /** @param  RendezVous  $resource */
    public function __construct($resource, private bool $reportDemande = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $aVenir = $this->status === StatutRendezVous::Prevu && $this->starts_at->isFuture();
        $acompte = $this->acompte();

        return [
            'id' => $this->id,
            'debute_le' => $this->starts_at->toIso8601String(),
            'termine_le' => $this->ends_at->toIso8601String(),
            'type' => $this->type->value,
            'motif' => $this->reason,
            'statut' => $this->status->value,
            'patient' => $this->patient
                ? ['id' => $this->patient->id, 'nom' => $this->patient->nom]
                : ['id' => null, 'nom' => $this->patient_name],
            'clinique' => ['id' => $this->clinique->id, 'nom' => $this->clinique->nom],
            'veterinaire' => $this->lignePlanning?->nom,
            'peut_annuler' => $aVenir && ! $acompte,
            'peut_demander_annulation' => $aVenir && $acompte && $acompte['annulation'] === null,
            'peut_reporter' => $aVenir && ! $this->reportDemande,
            'report_demande' => $aVenir && $this->reportDemande,
            // Acompte payé à la réservation, jamais rendu d'office
            'acompte' => $acompte,
        ];
    }

    /**
     * L'acompte payé, ce qui en a été rendu, et la demande d'annulation
     * (en attente ou tranchée : complet, hors_frais, sans, refusee).
     *
     * @return array<string, mixed>|null
     */
    private function acompte(): ?array
    {
        $demande = $this->relationLoaded('demandeEnLigne') ? $this->demandeEnLigne : null;

        if (! $demande?->acompte_paye_le) {
            return null;
        }

        return [
            'montant' => round(($demande->acompte_centimes ?? 0) / 100, 2),
            'rembourse' => $demande->acompte_rembourse_le !== null,
            'montant_rendu' => round(($demande->acompte_rembourse_centimes ?? 0) / 100, 2),
            'annulation' => $demande->annulation_demandee_le ? [
                'demandee_le' => $demande->annulation_demandee_le->toIso8601String(),
                'decision' => $demande->annulation_decision?->value,
            ] : null,
        ];
    }
}
