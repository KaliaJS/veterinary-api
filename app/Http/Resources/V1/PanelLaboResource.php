<?php

namespace App\Http\Resources\V1;

use App\Models\PanelLabo;
use App\Models\PanelParametre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PanelLabo
 */
class PanelLaboResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'tube' => $this->tube,
            'parametres' => $this->whenLoaded('parametres', fn () => $this->parametres->map(fn (PanelParametre $parametre): array => [
                'nom' => $parametre->nom,
                'unite' => $parametre->unite,
                'borne_basse' => $parametre->borne_basse,
                'borne_haute' => $parametre->borne_haute,
            ])),
        ];
    }
}
