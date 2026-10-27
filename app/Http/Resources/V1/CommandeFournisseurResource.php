<?php

namespace App\Http\Resources\V1;

use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
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
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'fournisseur' => $this->fournisseur,
            'statut' => $this->statut,
            'envoyee_le' => $this->envoyee_le,
            'receptionnee_le' => $this->receptionnee_le,
            // Envoyée (ou en route) par Vetpoint (28.10.2026)
            'vetpoint' => $this->vetpoint_commande_id !== null || $this->vetpoint_envoi_le !== null ? [
                'numero' => $this->vetpoint_commande_id,
                'livraison' => $this->vetpoint_livraison,
                'date_souhaitee' => $this->vetpoint_date_souhaitee?->toDateString(),
                'envoi_en_cours' => $this->vetpoint_commande_id === null,
            ] : null,
            // Réception d'une commande Vetpoint : prix réels lus ou non (28.10.2026)
            'reception_vetpoint' => $this->when($this->resource->receptionVetpoint !== null, fn () => $this->resource->receptionVetpoint),
            'lignes' => $this->whenLoaded(
                'lignes',
                fn () => $this->lignes->map(fn (CommandeFournisseurLigne $ligne): array => [
                    'id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'cout' => (float) $ligne->cout,
                ])->values(),
            ),
        ];
    }
}
