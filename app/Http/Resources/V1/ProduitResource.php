<?php

namespace App\Http\Resources\V1;

use App\Auth\ContexteClinique;
use App\Models\ProductOrderSetting;
use App\Models\ProductSource;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La fiche est d'organisation, les quantités par clinique : `stocks`
 * expose la ventilation complète (parité front — l'écran affiche les
 * deux cliniques), `stock_total` et `sous_seuil` sont dérivés, jamais
 * stockés. La péremption sort au format du front « MM.YYYY ».
 *
 * @mixin Produit
 */
class ProduitResource extends JsonResource
{
    private bool $isOrderSettingRead = false;

    private ?ProductOrderSetting $orderSettingRead = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'categorie' => $this->categorie,
            'reference' => $this->reference,
            'prix' => (float) $this->prix,
            // Le taux de TVA à la vente (reduced, normal) et son pourcentage (03.11.2026)
            'vat_rate' => $this->vat_rate,
            'vat_rate_percent' => $this->vat_rate->percent(),
            'cout' => (float) $this->cout,
            'unite' => $this->unite,
            // Ce que contient une unité (médicaments à la dose, 03.11.2026)
            'dose_unit' => $this->dose_unit,
            'content_per_unit' => $this->content_per_unit,
            'seuil_alerte' => $this->seuil_alerte,
            'est_boutique' => $this->est_boutique,
            'est_sur_ordonnance' => $this->est_sur_ordonnance,
            'est_stupefiant' => $this->est_stupefiant,
            'tableau_stupefiant' => $this->tableau_stupefiant,
            'peremption' => $this->peremptionAffichee(),
            'fournisseur' => $this->fournisseur,
            'ean' => $this->ean,
            'atc_code' => $this->atc_code,
            // Les principes actifs du catalogue, par le code-barres (allergies, 03.11.2026)
            'substances' => $this->resource->catalogueSubstances(),
            'promo_pct' => $this->promo_pct,
            'est_vedette' => $this->est_vedette,
            // La photo de la vitrine (nouvel article B, 03.11.2026)
            'photo_url' => $this->resource->photoUrl(),
            // Commander automatiquement sous le seuil, jusqu'où, l'écart décidé par la clinique, et ses
            // sources : tout dans la clinique ouverte (par clinique depuis le 04.11.2026)
            'is_auto_order' => (bool) $this->orderSetting()?->is_auto_order,
            'reorder_up_to' => $this->orderSetting()?->reorder_up_to,
            'auto_excluded' => $this->excluded(),
            'sources' => app(ContexteClinique::class)->estDefini()
                ? $this->resource->sourcesIn((int) app(ContexteClinique::class)->cliniqueId())->map(fn (ProductSource $source): array => [
                    'id' => $source->id,
                    'supplier_id' => $source->supplier_id,
                    'supplier_name' => $source->supplier?->name,
                    'channel' => $source->supplier?->channel,
                    'rank' => $source->rank,
                    'reference' => $source->reference,
                    'cost' => $source->cost !== null ? (float) $source->cost : null,
                ])->values()->all()
                : [],
            // Produit Vetpoint relié (27.10.2026) ; null : aucun
            'vetpoint' => $this->whenLoaded('vetpoint', fn (): ?array => $this->vetpoint ? [
                'id' => $this->vetpoint->vetpoint_id,
                'nom' => $this->vetpoint->nom,
                'swissmedic' => $this->vetpoint->swissmedic,
            ] : null),
            'stocks' => $this->whenLoaded(
                'stocks',
                fn () => $this->stocks
                    ->map(fn (Stock $stock): array => [
                        'clinique_id' => $stock->clinique_id,
                        'quantite' => $stock->quantite,
                        // Le reste de l'unité entamée
                        'opened_rest' => $stock->opened_rest,
                        // Quand cette unité a été ouverte (cinquième vérification)
                        'opened_at' => $stock->opened_at?->toIso8601String(),
                    ])
                    ->values(),
            ),
            'stock_total' => $this->whenLoaded('stocks', fn (): int => $this->stockTotal()),
            'sous_seuil' => $this->whenLoaded('stocks', fn (): bool => $this->estSousLeSeuil()),
            // Sous le seuil dans la clinique du jeton : ce que « Commander le stock bas » commandera
            'sous_seuil_clinique' => $this->whenLoaded('stocks', fn (): ?bool => app(ContexteClinique::class)->estDefini() ? $this->estSousLeSeuilDans((int) app(ContexteClinique::class)->cliniqueId()) : null),
        ];
    }

    /** Le réglage de la commande automatique dans la clinique ouverte, lu une fois */
    private function orderSetting(): ?ProductOrderSetting
    {
        $contexte = app(ContexteClinique::class);
        if (! $contexte->estDefini()) {
            return null;
        }

        if (! $this->isOrderSettingRead) {
            $this->orderSettingRead = $this->resource->orderSettingIn((int) $contexte->cliniqueId());
            $this->isOrderSettingRead = true;
        }

        return $this->orderSettingRead;
    }

    /**
     * L'écart décidé par la clinique : quand, pourquoi, par qui ; null sinon.
     *
     * @return array{at: string, reason: ?string, by: ?string}|null
     */
    private function excluded(): ?array
    {
        $setting = $this->orderSetting();
        if ($setting === null || $setting->excluded_at === null) {
            return null;
        }

        return ['at' => $setting->excluded_at->toIso8601String(), 'reason' => $setting->excluded_reason, 'by' => $setting->excludedBy?->name];
    }
}
