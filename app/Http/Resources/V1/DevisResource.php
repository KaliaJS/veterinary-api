<?php

namespace App\Http\Resources\V1;

use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Facture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `statut` est le statut stocké, `statut_effectif` la valeur affichée
 * (un envoyé hors validité sort `expire`) ; totaux et TVA (8.1 %,
 * extraite du TTC remisé comme sur la facture) dérivés des lignes et de
 * la remise.
 *
 * @mixin Devis
 */
class DevisResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'emis_le' => $this->emis_le->toDateString(),
            'patient_id' => $this->patient_id,
            'motif' => $this->motif,
            'remise_pct' => $this->remise_pct,
            'alea_pct' => $this->alea_pct,
            'validite_jours' => $this->validite_jours,
            'valide_jusquau' => $this->valide_jusquau?->toDateString(),
            'acompte_pct' => $this->acompte_pct,
            'conditions' => $this->conditions,
            'statut' => $this->statut,
            'statut_effectif' => $this->statutEffectif(),
            'canal' => $this->canal,
            'signe_le' => $this->signe_le,
            'signe_par' => $this->signe_par,
            'acompte_facture_id' => $this->acompte_facture_id,
            'facture_finale_id' => $this->facture_finale_id,
            'sous_total' => $this->whenLoaded('lignes', fn (): float => $this->sousTotal()),
            'total_ttc' => $this->whenLoaded('lignes', fn (): float => $this->totalTtc()),
            'montant_tva' => $this->whenLoaded('lignes', fn (): float => $this->montantTva()),
            'total_ht' => $this->whenLoaded('lignes', fn (): float => $this->totalHt()),
            'montant_remise' => $this->whenLoaded('lignes', fn (): float => $this->montantRemise()),
            'montant_acompte' => $this->whenLoaded('lignes', fn (): float => $this->montantAcompte()),
            'total_avec_alea' => $this->whenLoaded('lignes', fn (): float => $this->totalAvecAlea()),
            'taux_tva' => Facture::TAUX_TVA * 100,
            'lignes' => $this->whenLoaded(
                'lignes',
                fn () => $this->lignes->map(fn (DevisLigne $ligne): array => [
                    'id' => $ligne->id,
                    'libelle' => $ligne->libelle,
                    'quantite' => $ligne->quantite,
                    'prix_chf' => (float) $ligne->prix_chf,
                    'total_chf' => round($ligne->quantite * (float) $ligne->prix_chf, 2),
                    'type' => $ligne->type,
                    'produit_id' => $ligne->produit_id,
                ])->values(),
            ),
            'patient' => new PatientResource($this->whenLoaded('patient')),
        ];
    }
}
