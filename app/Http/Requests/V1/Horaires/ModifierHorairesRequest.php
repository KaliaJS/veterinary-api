<?php

namespace App\Http\Requests\V1\Horaires;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Horaires jour par jour (demande de Jim, 04.11.2026) : les sept jours,
 * du lundi au dimanche, chacun ouvert ou fermé, avec une ou deux plages
 * (matin et après-midi). Au pas de 15 minutes ; une plage dure au moins
 * 30 minutes ; l'après-midi reprend au moins 15 minutes après la fin du
 * matin. Un jour fermé peut garder ses plages, pour les retrouver quand
 * il rouvre. Chaque erreur se range sous son jour (`days.2.ranges.1.start`).
 */
class ModifierHorairesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'size:7'],
            'days.*' => ['required', 'array'],
            'days.*.is_open' => ['required', 'boolean'],
            'days.*.ranges' => ['present', 'array', 'max:2'],
            'days.*.ranges.*.start' => ['required', 'date_format:H:i'],
            'days.*.ranges.*.end' => ['required', 'date_format:H:i'],
            // L'onglet qui envoie (tiré au hasard à son ouverture) et son compteur : un envoi plus
            // ancien du même onglet n'écrit pas ; un grand numéro ne bloque que son onglet
            'writer' => ['sometimes', 'nullable', 'uuid'],
            'seq' => ['required_with:writer', 'nullable', 'integer', 'between:1,1000000000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $minutes = fn (string $heure): int => (int) substr($heure, 0, 2) * 60 + (int) substr($heure, 3, 2);

            foreach ((array) $this->input('days') as $i => $jour) {
                // Un jour déjà refusé par les règles (forme, nombre de plages) ne se vérifie pas plus loin
                if (! is_array($jour) || $validator->errors()->hasAny(["days.$i", "days.$i.is_open", "days.$i.ranges", "days.$i.ranges.*"])) {
                    continue;
                }
                $plages = array_values((array) ($jour['ranges'] ?? []));

                if (filter_var($jour['is_open'] ?? false, FILTER_VALIDATE_BOOLEAN) && $plages === []) {
                    $validator->errors()->add("days.$i.ranges", 'Indiquez au moins une plage pour un jour ouvert.');
                }

                foreach ($plages as $j => $plage) {
                    $cle = "days.$i.ranges.$j";
                    $debut = $minutes($plage['start']);
                    $fin = $minutes($plage['end']);

                    foreach (['start' => $debut, 'end' => $fin] as $champ => $valeur) {
                        if ($valeur % 15 !== 0) {
                            $validator->errors()->add("$cle.$champ", 'Les horaires se règlent au pas de 15 minutes.');
                        }
                    }

                    if ($fin < $debut + 30) {
                        $validator->errors()->add("$cle.end", 'Une plage dure au moins 30 minutes.');
                    }

                    if ($j > 0 && $debut < $minutes($plages[$j - 1]['end']) + 15) {
                        $validator->errors()->add("$cle.start", 'L’après-midi reprend au moins 15 minutes après la fin du matin.');
                    }
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'days.size' => 'Les horaires comptent les sept jours de la semaine.',
            'days.*.ranges.max' => 'Deux plages au plus par jour : le matin et l’après-midi.',
            'days.*.ranges.*.start.date_format' => 'Une heure au format 08:00.',
            'days.*.ranges.*.end.date_format' => 'Une heure au format 18:00.',
        ];
    }

    public function writer(): ?string
    {
        return $this->validated('writer');
    }

    public function seq(): ?int
    {
        $seq = $this->validated('seq');

        return $seq === null ? null : (int) $seq;
    }

    /**
     * Les sept jours tels qu'ils s'enregistrent : ouvert ou fermé, et les
     * plages dans l'ordre de la journée.
     *
     * @return list<array{is_open: bool, ranges: list<array{start: string, end: string}>}>
     */
    public function days(): array
    {
        return array_map(fn (array $jour): array => [
            'is_open' => (bool) $jour['is_open'],
            'ranges' => array_values(array_map(fn (array $plage): array => ['start' => $plage['start'], 'end' => $plage['end']], $jour['ranges'] ?? [])),
        ], array_values($this->validated('days')));
    }
}
