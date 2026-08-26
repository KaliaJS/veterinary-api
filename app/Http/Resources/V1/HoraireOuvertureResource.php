<?php

namespace App\Http\Resources\V1;

use App\Models\HoraireOuverture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Heures LOCALES rendues « HH:MM » (les colonnes `time` remontent avec
 * les secondes) ; le fuseau de la clinique accompagne la réponse pour
 * que le front situe la grille par rapport aux datetimes UTC des
 * rendez-vous.
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
            'fuseau_horaire' => $this->clinique->fuseau_horaire,
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
