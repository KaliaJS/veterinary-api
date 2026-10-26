<?php

namespace App\Http\Resources\V1;

use App\Models\ClotureCaisse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClotureCaisse
 */
class ClotureCaisseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'arretee_le' => $this->arretee_le,
            'user_id' => $this->user_id,
            'total_tpe' => (float) $this->total_tpe,
            'total_twint' => (float) $this->total_twint,
            'total_especes' => (float) $this->total_especes,
            'total_encaisse' => round((float) $this->total_tpe + (float) $this->total_twint + (float) $this->total_especes, 2),
            'fond' => (float) $this->fond,
            'especes_theoriques' => round((float) $this->fond + (float) $this->total_especes, 2),
            'especes_comptees' => (float) $this->especes_comptees,
            'ecart' => (float) $this->ecart,
            'depot' => (float) $this->depot,
            'nouveau_fond' => (float) $this->nouveau_fond,
            // Compté mais ni déposé ni laissé en fond (justifié dans la note)
            'non_ventile' => max(0.0, round((float) $this->especes_comptees - (float) $this->depot - (float) $this->nouveau_fond, 2)),
            'note' => $this->note,
            'nombre_factures' => $this->whenCounted('factures'),
            'facture_ids' => $this->whenLoaded('factures', fn () => $this->factures->pluck('id')),
        ];
    }
}
