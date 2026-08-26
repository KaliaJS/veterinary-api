<?php

namespace App\Http\Requests\V1\Horaires;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Invariants des réglages (docs/data-model.md §3 — Horaires) : pas de
 * 15 min, fermeture ≥ ouverture + 2 h, pause commençant ≥ 30 min après
 * l'ouverture, finissant ≥ 30 min avant la fermeture, durée ≥ 15 min.
 */
class ModifierHorairesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ouverture' => ['required', 'date_format:H:i'],
            'fermeture' => ['required', 'date_format:H:i'],
            'fermeture_samedi' => ['sometimes', 'nullable', 'date_format:H:i'],
            'samedi_ferme' => ['sometimes', 'boolean'],
            'dimanche_ferme' => ['sometimes', 'boolean'],
            'pause_active' => ['sometimes', 'boolean'],
            'pause_debut' => ['nullable', 'date_format:H:i', 'required_if_accepted:pause_active'],
            'pause_fin' => ['nullable', 'date_format:H:i', 'required_if_accepted:pause_active'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $minutes = fn (?string $heure): ?int => $heure === null
                ? null
                : (int) substr($heure, 0, 2) * 60 + (int) substr($heure, 3, 2);

            $champs = ['ouverture', 'fermeture', 'fermeture_samedi', 'pause_debut', 'pause_fin'];

            foreach ($champs as $champ) {
                $valeur = $minutes($this->input($champ));

                if ($valeur !== null && $valeur % 15 !== 0) {
                    $validator->errors()->add($champ, 'Les horaires se règlent au pas de 15 minutes.');
                }
            }

            $ouverture = $minutes($this->input('ouverture'));
            $fermeture = $minutes($this->input('fermeture'));
            $fermetureSamedi = $minutes($this->input('fermeture_samedi'));

            if ($ouverture === null || $fermeture === null) {
                return;
            }

            if ($fermeture < $ouverture + 120) {
                $validator->errors()->add('fermeture', 'La fermeture doit suivre l’ouverture d’au moins 2 heures.');
            }

            if ($fermetureSamedi !== null && $fermetureSamedi < $ouverture + 120) {
                $validator->errors()->add('fermeture_samedi', 'La fermeture du samedi doit suivre l’ouverture d’au moins 2 heures.');
            }

            $pauseDebut = $minutes($this->input('pause_debut'));
            $pauseFin = $minutes($this->input('pause_fin'));

            if ($pauseDebut === null || $pauseFin === null) {
                return;
            }

            if ($pauseDebut < $ouverture + 30) {
                $validator->errors()->add('pause_debut', 'La pause doit commencer au moins 30 minutes après l’ouverture.');
            }

            if ($pauseFin > $fermeture - 30) {
                $validator->errors()->add('pause_fin', 'La pause doit finir au moins 30 minutes avant la fermeture.');
            }

            if ($pauseFin - $pauseDebut < 15) {
                $validator->errors()->add('pause_fin', 'La pause dure au moins 15 minutes.');
            }
        });
    }
}
