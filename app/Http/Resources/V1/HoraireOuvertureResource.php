<?php

namespace App\Http\Resources\V1;

use App\Models\HoraireOuverture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Heures rendues « HH:MM » (les colonnes `time` remontent avec les
 * secondes).
 *
 * @mixin HoraireOuverture
 */
class HoraireOuvertureResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $heure = fn (?string $valeur): ?string => $valeur === null ? null : substr($valeur, 0, 5);

        return [
            'ouverture' => $heure($this->ouverture),
            'fermeture' => $heure($this->fermeture),
            'fermeture_samedi' => $heure($this->fermeture_samedi),
            'samedi_ferme' => $this->samedi_ferme,
            'dimanche_ferme' => $this->dimanche_ferme,
            'pause_active' => $this->pause_active,
            'pause_debut' => $heure($this->pause_debut),
            'pause_fin' => $heure($this->pause_fin),
        ];
    }
}
