<?php

namespace App\Http\Resources\V1;

use App\Models\MouvementStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MouvementStock
 */
class MouvementStockResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'produit_id' => $this->produit_id,
            'clinique_id' => $this->clinique_id,
            'quantite' => $this->quantite,
            // Médicaments à la dose (03.11.2026) : la dose sortie ou rendue, signée
            'dose' => $this->dose,
            // L'unité figée au mouvement (sixième vérification), sinon celle de l'article
            'dose_unit' => $this->dose !== null ? ($this->dose_unit ?? $this->produit?->dose_unit) : null,
            'libelle' => $this->libelle,
            'motif' => $this->motif,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'created_at' => $this->created_at,
            // Les lots pris ou rendus par ce mouvement (FEFO, 04.11.2026) ; numéro vide : sans lot connu
            'lots' => $this->whenLoaded('lotAllocations', fn (): array => $this->lotAllocations->map(fn ($allocation): array => [
                'lot_id' => $allocation->lot_id,
                'numero_lot' => $allocation->lot_number,
                'peremption' => $allocation->expires_on?->format('m.Y'),
                'quantite' => $allocation->quantity,
            ])->values()->all()),
        ];
    }
}
