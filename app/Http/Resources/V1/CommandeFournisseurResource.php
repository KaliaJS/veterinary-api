<?php

namespace App\Http\Resources\V1;

use App\Auth\ContexteClinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Supplier;
use App\Services\Purchasing\PurchasingConnectors;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommandeFournisseur
 */
class CommandeFournisseurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Les lignes lues une fois : le total et leur nombre sont servis, jamais recalculés à l'écran
        $lignes = $this->resource->loadMissing('lignes')->lignes;
        $centimes = fn (CommandeFournisseurLigne $ligne): int => $ligne->quantite * (int) round((float) $ligne->cout * 100);
        $supplier = $this->supplierOf();

        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'fournisseur' => $this->fournisseur,
            'statut' => $this->statut,
            'envoyee_le' => $this->envoyee_le,
            'receptionnee_le' => $this->receptionnee_le,
            'creee_le' => $this->created_at,
            // Au centime : somme des quantités × coûts des lignes (03.11.2026)
            'total_chf' => $lignes->sum($centimes) / 100,
            'nombre_lignes' => $lignes->count(),
            // Le mois de réception à l'heure de la clinique : le titre de mois de la liste
            'mois' => $this->receptionnee_le?->copy()->setTimezone($this->fuseau())->format('Y-m'),
            // Envoyée (ou en route) par Vetpoint (28.10.2026)
            'vetpoint' => $this->vetpoint_commande_id !== null || $this->vetpoint_envoi_le !== null ? [
                'numero' => $this->vetpoint_commande_id,
                'livraison' => $this->vetpoint_livraison,
                'date_souhaitee' => $this->vetpoint_date_souhaitee?->toDateString(),
                'envoi_en_cours' => $this->vetpoint_commande_id === null,
            ] : null,
            // Le fournisseur de la clinique et le panier automatique (03.11.2026)
            'supplier_id' => $this->supplier_id,
            'channel' => $supplier?->channel,
            'connector' => $supplier !== null && $supplier->channel === 'api' ? $supplier->connector : null,
            // Le nom de la centrale, dit par son connecteur (« Vetpoint ») : rien d'écrit en dur à l'écran
            'connector_name' => $supplier !== null && $supplier->channel === 'api' ? app(PurchasingConnectors::class)->get($supplier->connector)?->name() : null,
            'sent_to' => $this->sent_to,
            'is_auto' => (bool) $this->is_auto,
            'auto' => $this->is_auto ? [
                'minimum_order' => $supplier !== null ? (float) $supplier->minimum_order : 0.0,
                'deadline_at' => $this->deadline_at?->toIso8601String(),
                // La veille annoncée : à partir de quand, si le fournisseur le veut
                // L'avis, au moins 12 heures avant le départ : envoyé (le départ est annoncé), sinon prévu la veille
                'eve_notice_at' => $this->deadline_at?->copy()->subDay()->toIso8601String(),
                'is_noticed' => $this->deadline_at !== null && $this->eve_notice_for !== null && $this->deadline_at->equalTo($this->eve_notice_for),
                'under_minimum' => $supplier?->under_minimum,
                'moved_out' => $this->moved_out ?? [],
                'error' => $this->auto_error,
            ] : null,
            'is_paused' => $this->paused_at !== null,
            'is_awaiting_decision' => $this->resource->isAwaitingDecision(),
            // Son fournisseur a été retiré : un brouillon ordinaire, à envoyer en PDF (04.11.2026)
            'is_supplier_removed' => $this->supplier_removed_at !== null,
            // Réception d'une commande Vetpoint : prix réels lus ou non (28.10.2026)
            'reception_vetpoint' => $this->when($this->resource->receptionVetpoint !== null, fn () => $this->resource->receptionVetpoint),
            'lignes' => $this->whenLoaded(
                'lignes',
                fn () => $this->lignes->map(fn (CommandeFournisseurLigne $ligne): array => [
                    'id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'cout' => (float) $ligne->cout,
                    'total_chf' => $centimes($ligne) / 100,
                    // Pourquoi il est dans le panier automatique, et d'où il vient (03.11.2026)
                    'reason' => $ligne->reason_threshold !== null ? [
                        'stock' => $ligne->reason_stock,
                        'threshold' => $ligne->reason_threshold,
                        'at' => $ligne->reason_at?->toIso8601String(),
                    ] : null,
                    'moved_from' => $ligne->moved_from,
                ])->values(),
            ),
        ];
    }

    /** Le fournisseur de la clinique, lu une fois (hors du scope : la commande dit sa clinique) */
    private function supplierOf(): ?Supplier
    {
        if ($this->supplier_id === null) {
            return null;
        }
        if (! $this->resource->relationLoaded('supplier')) {
            $this->resource->setRelation('supplier', Supplier::withoutGlobalScopes()->find($this->supplier_id));
        }

        $supplier = $this->resource->getRelation('supplier');

        return $supplier instanceof Supplier ? $supplier : null;
    }

    /** Le fuseau de la clinique de la commande ; la clinique ouverte, déjà chargée, d'abord */
    private function fuseau(): string
    {
        $ouverte = app(ContexteClinique::class)->clinique();
        $clinique = $ouverte !== null && $ouverte->id === $this->clinique_id ? $ouverte : $this->clinique;

        return $clinique?->fuseau_horaire ?: 'Europe/Zurich';
    }
}
