<?php

namespace App\Http\Resources\V1;

use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Facture;
use App\Support\DocumentHeader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `statut` est le statut stocké, `statut_effectif` la valeur affichée
 * (un envoyé hors validité sort `expire`) ; totaux et TVA (au taux du
 * soin, selon l'espèce de l'animal depuis le 03.11.2026, extraite du TTC
 * remisé comme sur la facture) dérivés des lignes et de la remise.
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
            'clinique_id' => $this->clinique_id,
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
            'envoye_le' => $this->envoye_le,
            'relance_le' => $this->relance_le,
            'signe_le' => $this->signe_le,
            'signe_par' => $this->signe_par,
            'refuse_le' => $this->refuse_le,
            'refuse_par' => $this->refuse_par,
            'motif_refus' => $this->motif_refus,
            'acompte_facture_id' => $this->acompte_facture_id,
            'facture_finale_id' => $this->facture_finale_id,
            'sous_total' => $this->whenLoaded('lignes', fn (): float => $this->sousTotal()),
            'total_ttc' => $this->whenLoaded('lignes', fn (): float => $this->totalTtc()),
            'montant_tva' => $this->whenLoaded('lignes', fn (): float => $this->montantTva()),
            'total_ht' => $this->whenLoaded('lignes', fn (): float => $this->totalHt()),
            'montant_remise' => $this->whenLoaded('lignes', fn (): float => $this->montantRemise()),
            'montant_acompte' => $this->whenLoaded('lignes', fn (): float => $this->montantAcompte()),
            'total_avec_alea' => $this->whenLoaded('lignes', fn (): float => $this->totalAvecAlea()),
            // Le taux unique, ou null quand un médicament remis pour la maison en ajoute un (03.11.2026)
            'taux_tva' => $this->whenLoaded('lignes', fn (): ?float => $this->tauxUnique(), $this->tauxTva()),
            'vat_breakdown' => $this->whenLoaded('lignes', fn (): array => $this->vatBreakdown()),
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
                    'vat_context' => $ligne->vat_context,
                ])->values(),
            ),
            'patient' => new PatientResource($this->whenLoaded('patient')),
            // En-tête de la clinique qui l'établit (PDF)
            'en_tete' => $this->whenLoaded('clinique', fn (): ?array => DocumentHeader::forPrint($this->issuer, $this->clinique)),
            // Le client figé à l'envoi (02.11.2026) ; un brouillon suit la fiche
            'recipient' => $this->recipient ?? ($this->relationLoaded('patient') && $this->patient?->relationLoaded('client') ? DocumentHeader::recipient($this->patient->client) : null),
        ];
    }
}
