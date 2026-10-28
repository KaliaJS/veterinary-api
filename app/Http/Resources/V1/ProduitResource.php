<?php

namespace App\Http\Resources\V1;

use App\Auth\ContexteClinique;
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
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'categorie' => $this->categorie,
            'reference' => $this->reference,
            'prix' => (float) $this->prix,
            'cout' => (float) $this->cout,
            'unite' => $this->unite,
            'seuil_alerte' => $this->seuil_alerte,
            'est_boutique' => $this->est_boutique,
            'est_sur_ordonnance' => $this->est_sur_ordonnance,
            'est_stupefiant' => $this->est_stupefiant,
            'peremption' => $this->peremptionAffichee(),
            'fournisseur' => $this->fournisseur,
            'ean' => $this->ean,
            'promo_pct' => $this->promo_pct,
            'est_vedette' => $this->est_vedette,
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
                    ])
                    ->values(),
            ),
            'stock_total' => $this->whenLoaded('stocks', fn (): int => $this->stockTotal()),
            'sous_seuil' => $this->whenLoaded('stocks', fn (): bool => $this->estSousLeSeuil()),
            // Sous le seuil dans la clinique du jeton : ce que « Commander le stock bas » commandera
            'sous_seuil_clinique' => $this->whenLoaded('stocks', fn (): ?bool => app(ContexteClinique::class)->estDefini() ? $this->estSousLeSeuilDans((int) app(ContexteClinique::class)->cliniqueId()) : null),
        ];
    }
}
