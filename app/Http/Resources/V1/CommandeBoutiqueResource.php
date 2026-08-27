<?php

namespace App\Http\Resources\V1;

use App\Models\CommandeBoutique;
use App\Models\CommandeBoutiqueLigne;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommandeBoutique
 */
class CommandeBoutiqueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'client_id' => $this->client_id,
            'client_nom' => $this->client_nom,
            'statut' => $this->statut,
            'passee_le' => $this->passee_le,
            'retiree_le' => $this->retiree_le,
            'total_ttc' => $this->whenLoaded('lignes', fn (): float => $this->totalTtc()),
            'nombre_articles' => $this->whenLoaded('lignes', fn (): int => (int) $this->lignes->sum('quantite')),
            'lignes' => $this->whenLoaded(
                'lignes',
                fn () => $this->lignes->map(fn (CommandeBoutiqueLigne $ligne): array => [
                    'id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->relationLoaded('produit') ? $ligne->produit->nom : null,
                    'quantite' => $ligne->quantite,
                    'prix_chf' => (float) $ligne->prix_chf,
                ])->values(),
            ),
        ];
    }
}
