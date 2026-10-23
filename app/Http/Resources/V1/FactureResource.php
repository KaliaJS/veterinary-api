<?php

namespace App\Http\Resources\V1;

use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\FacturePrescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Total TTC et TVA (8.1 %) sont dérivés des lignes — jamais stockés.
 * L'assureur affiché vient du décompte (0..1).
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
            'stock_sorti' => $this->stock_sorti,
            'cloture_caisse_id' => $this->cloture_caisse_id,
            // Liste des factures seulement (withExists) : encaissée par Stripe
            'paye_par_stripe' => $this->when(
                array_key_exists('paye_par_stripe', $this->getAttributes()),
                fn (): bool => (bool) $this->paye_par_stripe,
            ),
            'total_ttc' => $this->whenLoaded('lignes', fn (): float => $this->totalTtc()),
            // Acompte payé en ligne à la réservation, et ce qui reste à encaisser
            'acompte' => (float) $this->acompte,
            'reste_a_payer' => $this->whenLoaded('lignes', fn (): float => $this->resteAPayer()),
            'montant_tva' => $this->whenLoaded('lignes', fn (): float => $this->montantTva()),
            'total_ht' => $this->whenLoaded('lignes', fn (): float => $this->totalHt()),
            'taux_tva' => Facture::TAUX_TVA * 100,
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
            'en_tete' => $this->whenLoaded('clinique', fn (): array => $this->clinique->enTeteDocument()),
        ];
    }
}
