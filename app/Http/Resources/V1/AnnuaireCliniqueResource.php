<?php

namespace App\Http\Resources\V1;

use App\Models\Clinique;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrée d'annuaire d'une clinique : identité et capacité — jamais
 * les champs de gestion de CliniqueResource (écran Organisation).
 *
 * @mixin Clinique
 */
class AnnuaireCliniqueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'nombre_boxes' => $this->nombre_boxes,
        ];
    }
}
