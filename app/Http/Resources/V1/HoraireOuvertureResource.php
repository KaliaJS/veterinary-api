<?php

namespace App\Http\Resources\V1;

use App\Models\HoraireOuverture;
use App\Services\Planning\AppointmentsOutsideHours;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Horaires jour par jour (04.11.2026) : `days`, du lundi (`day` 1) au
 * dimanche (7), chacun ouvert ou fermé avec ses plages « HH:MM ». Heures
 * LOCALES : le fuseau de la clinique accompagne la réponse pour que le
 * front situe la grille par rapport aux datetimes UTC des rendez-vous.
 * Les rendez-vous à venir qui tombent hors des plages accompagnent aussi
 * la réponse, pour que la fiche les signale.
 *
 * @mixin HoraireOuverture
 */
class HoraireOuvertureResource extends JsonResource
{
    /** Écrit par cet envoi (une lecture : vrai) */
    private bool $applied = true;

    public function applied(bool $applied): static
    {
        $this->applied = $applied;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $jours = $this->days ?? HoraireOuverture::DEFAUTS['days'];

        return [
            'fuseau_horaire' => $this->clinique->fuseau_horaire,
            // L'envoi a-t-il été écrit ? Faux : un envoi plus récent du même onglet l'avait devancé,
            // l'état en base est rendu (sixième passe)
            'applied' => $this->applied,
            'days' => array_map(fn (int $i): array => [
                'day' => $i + 1,
                'is_open' => (bool) ($jours[$i]['is_open'] ?? false),
                'ranges' => array_values(array_map(
                    fn (array $plage): array => ['start' => substr($plage['start'], 0, 5), 'end' => substr($plage['end'], 0, 5)],
                    $jours[$i]['ranges'] ?? [],
                )),
            ], range(0, 6)),
            // Les rendez-vous à venir hors de ces horaires, signalés dans la fiche (04.11.2026)
            'appointments_outside_hours' => app(AppointmentsOutsideHours::class)->for($this->resource),
        ];
    }
}
