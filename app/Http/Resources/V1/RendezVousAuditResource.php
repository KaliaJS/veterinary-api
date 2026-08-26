<?php

namespace App\Http\Resources\V1;

use App\Models\RendezVousAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrée d'audit brute — le front regroupe par `batch_id` (un lot =
 * un geste) pour composer l'historique de la fiche RDV.
 *
 * @mixin RendezVousAudit
 */
class RendezVousAuditResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'champ' => $this->champ,
            'ancienne_valeur' => $this->ancienne_valeur,
            'nouvelle_valeur' => $this->nouvelle_valeur,
            'user' => $this->user === null ? null : [
                'id' => $this->user->id,
                'nom' => $this->user->name,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
