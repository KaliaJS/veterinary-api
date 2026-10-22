<?php

namespace App\Http\Resources\V1\Espace;

use App\Enums\StatutRendezVous;
use App\Models\RendezVous;
use App\Services\Paiements\AcomptesRendezVous;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rendez-vous vu par le propriétaire (« Mes rendez-vous », accueil) :
 * ni no-shows ni compte rendu — ce sont des données de la clinique.
 * `peut_annuler` / `peut_reporter` : prévu et encore à venir.
 *
 * @mixin RendezVous
 */
class RendezVousProprietaireResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $aVenir = $this->status === StatutRendezVous::Prevu && $this->starts_at?->isFuture();

        return [
            'id' => $this->id,
            'debute_le' => $this->starts_at?->toIso8601String(),
            'termine_le' => $this->ends_at?->toIso8601String(),
            'type' => $this->type?->value ?? $this->type,
            'motif' => $this->reason,
            'statut' => $this->status?->value ?? $this->status,
            'patient' => $this->patient
                ? ['id' => $this->patient->id, 'nom' => $this->patient->nom]
                : ['id' => null, 'nom' => $this->patient_name],
            'clinique' => ['id' => $this->clinique->id, 'nom' => $this->clinique->nom],
            'veterinaire' => $this->lignePlanning?->nom,
            'peut_annuler' => $aVenir,
            'peut_reporter' => $aVenir,
            // Acompte payé à la réservation : rendu si l'on annule assez tôt
            'acompte' => $this->acompte($aVenir),
        ];
    }

    /** @return array{montant: float, rembourse: bool, remboursable: bool, delai_heures: int}|null */
    private function acompte(bool $aVenir): ?array
    {
        $demande = $this->relationLoaded('demandeEnLigne') ? $this->demandeEnLigne : null;

        if (! $demande?->acompte_paye_le) {
            return null;
        }

        return [
            'montant' => round(($demande->acompte_centimes ?? 0) / 100, 2),
            'rembourse' => $demande->acompte_rembourse_le !== null,
            'remboursable' => $aVenir && $demande->acompte_rembourse_le === null && app(AcomptesRendezVous::class)->estRemboursable($this->resource, $demande),
            'delai_heures' => $demande->clinique?->acompte_delai_heures ?? 24,
        ];
    }
}
