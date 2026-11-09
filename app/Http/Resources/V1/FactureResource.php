<?php

namespace App\Http\Resources\V1;

use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\FacturePrescription;
use App\Models\Settlement;
use App\Support\DocumentHeader;
use App\Support\QrFacture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Total TTC et TVA sont dérivés des lignes — jamais stockés. Chaque ligne
 * a son taux, figé à l'émission ; la TVA se sert taux par taux
 * (`vat_breakdown`, 03.11.2026). L'assureur affiché vient du décompte (0..1).
 *
 * @mixin Facture
 */
class FactureResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'emise_le' => $this->emise_le,
            'patient_id' => $this->patient_id,
            'client_id' => $this->client_id,
            'patient_name' => $this->patient_name,
            'species' => $this->species,
            'owner_name' => $this->owner_name,
            'ligne_planning_id' => $this->ligne_planning_id,
            'rendez_vous_id' => $this->rendez_vous_id,
            'ordonnance_id' => $this->ordonnance_id,
            'statut' => $this->statut,
            'moyen_paiement' => $this->moyen_paiement,
            'payee_le' => $this->payee_le,
            // Vente du comptoir (28.10.2026) : payée ou annulée depuis la boutique
            'vente_comptoir' => (bool) $this->vente_comptoir,
            'annulee_le' => $this->annulee_le,
            'stock_sorti' => $this->stock_sorti,
            'cloture_caisse_id' => $this->cloture_caisse_id,
            // Qui a encaissé : une carte ou un TWINT saisi sans terminal se voit avec son auteur
            'encaissee_par' => $this->whenLoaded('encaisseePar', fn (): ?array => $this->encaisseePar === null ? null : [
                'id' => $this->encaisseePar->id,
                'nom' => $this->encaisseePar->name,
            ]),
            // Liste des factures seulement (withExists) : encaissée par Stripe
            'paye_par_stripe' => $this->when(
                array_key_exists('paye_par_stripe', $this->getAttributes()),
                fn (): bool => (bool) $this->paye_par_stripe,
            ),
            'total_ttc' => $this->whenLoaded('lignes', fn (): float => $this->totalTtc()),
            // Acompte payé en ligne à la réservation, et ce qui reste à encaisser
            'acompte' => (float) $this->acompte,
            'reste_a_payer' => $this->whenLoaded('lignes', fn (): float => $this->resteAPayer()),
            // Ses règlements (09.11.2026) : ce qui est réglé, payée en partie, et chaque ligne
            'montant_regle' => $this->montantRegle(),
            'payee_en_partie' => $this->estPayeeEnPartie(),
            'reglements' => $this->whenLoaded('settlements', fn () => $this->settlements->map(fn (Settlement $s): array => [
                'id' => $s->id,
                'moyen' => $s->method->value,
                'libelle_moyen' => $s->method->label(),
                'montant' => (float) $s->amount,
                'recu_le' => $s->received_on->toDateString(),
                'reference' => $s->reference,
                'motif' => $s->reason,
                'annule_id' => $s->reverses_id,
                'par_stripe' => $s->paiement_id !== null,
                'saisi_par' => $s->relationLoaded('recordedBy') ? $s->recordedBy?->name : null,
                'saisi_le' => $s->created_at,
                // Annulable à la main (ni Stripe, ni acompte, ni déjà annulé)
                'annulable' => $s->isReversible(),
            ])->values()),
            'montant_tva' => $this->whenLoaded('lignes', fn (): float => $this->montantTva()),
            'total_ht' => $this->whenLoaded('lignes', fn (): float => $this->totalHt()),
            // Le taux de toutes les lignes, ou null quand elles en ont plusieurs
            'taux_tva' => $this->whenLoaded('lignes', fn (): ?float => $this->tauxUnique(), (float) $this->taux_tva),
            // Hors taxe, TVA et TTC de chaque taux ; vide pour une société non assujettie (03.11.2026)
            'vat_breakdown' => $this->whenLoaded('lignes', fn (): array => $this->vatBreakdown()),
            // Elle contient un soin : un article peut s'y dire donné pendant le soin (caisse)
            'has_care' => $this->whenLoaded('lignes', fn (): bool => $this->hasCare()),
            'lignes' => $this->whenLoaded(
                'lignes',
                fn () => $this->lignes->map(fn (FactureLigne $ligne): array => [
                    'id' => $ligne->id,
                    'libelle' => $ligne->libelle,
                    'quantite' => $ligne->quantite,
                    'prix_chf' => (float) $ligne->prix_chf,
                    'total_chf' => round($ligne->quantite * (float) $ligne->prix_chf, 2),
                    'type' => $ligne->type,
                    'produit_id' => $ligne->produit_id,
                    // Son taux, figé à l'émission (celui de la facture pour une ligne d'avant ; null : remise répartie), et pourquoi
                    'taux_tva' => $ligne->vatRate($this->resource),
                    'vat_context' => $ligne->vat_context,
                ])->values(),
            ),
            'prescriptions' => $this->whenLoaded(
                'prescriptions',
                fn () => $this->prescriptions->map(fn (FacturePrescription $prescription): array => [
                    'id' => $prescription->id,
                    'nom' => $prescription->nom,
                    'posologie' => $prescription->posologie,
                    'mode' => $prescription->mode,
                ])->values(),
            ),
            'assureur' => $this->whenLoaded('decompte', fn (): ?string => $this->decompte?->assureur),
            'decompte' => new DecompteAssuranceResource($this->whenLoaded('decompte')),
            // L'en-tête et le destinataire figés à l'émission (02.11.2026)
            'en_tete' => $this->whenLoaded('clinique', fn (): ?array => DocumentHeader::forPrint($this->issuer, $this->clinique)),
            'recipient' => $this->recipient,
            // La section paiement de la QR-facture suisse, tant qu'il reste à payer (01.11.2026)
            'qr_facture' => $this->when(
                $this->relationLoaded('clinique') && $this->relationLoaded('client') && $this->relationLoaded('lignes'),
                fn (): ?array => QrFacture::pour($this->resource),
            ),
        ];
    }
}
