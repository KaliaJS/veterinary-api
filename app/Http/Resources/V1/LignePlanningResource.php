<?php

namespace App\Http\Resources\V1;

use App\Models\LignePlanning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LignePlanning
 */
class LignePlanningResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employe_id' => $this->employe_id,
            'salle_id' => $this->salle_id,
            'nom' => $this->nom,
            'sous_titre' => $this->sous_titre,
            'role' => $this->role,
            'initiales' => $this->initiales,
            'groupe' => $this->groupe,
            'ordre' => $this->ordre,
        ];
    }
}
