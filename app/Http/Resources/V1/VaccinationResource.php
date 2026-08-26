<?php

namespace App\Http\Resources\V1;

use App\Models\Vaccination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le statut d'échéance (ÉCHU / BIENTÔT / À JOUR) est un dérivé
 * d'affichage : le front le calcule des dates.
 *
 * @mixin Vaccination
 */
class VaccinationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'marque' => $this->marque,
            'lot' => $this->lot,
            'employe_id' => $this->employe_id,
            'veterinaire' => $this->whenLoaded('employe', fn () => $this->employe?->nom),
            'injectee_le' => $this->injectee_le->toDateString(),
            'echeance_le' => $this->echeance_le->toDateString(),
        ];
    }
}
