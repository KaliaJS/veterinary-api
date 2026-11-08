<?php

namespace App\Http\Requests\V1\Concerns;

/**
 * Ce que le vétérinaire saisit pour le SI ABV avec une ordonnance
 * (08.11.2026) : la déclaration (`isabv`) et, par ligne, le dosage. Tout est
 * facultatif : ce qui manque laisse la déclaration à compléter, sans jamais
 * bloquer la prescription. Les codes sont vérifiés à la déclaration (listes
 * de l'OSAV).
 */
trait RulesForIsabv
{
    /** @return array<string, mixed> */
    protected function isabvRules(string $linesKey): array
    {
        return [
            'isabv' => ['sometimes', 'nullable', 'array'],
            'isabv.species_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'isabv.is_stock_supply' => ['sometimes', 'boolean'],
            'isabv.weight_kg' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:5000'],
            'isabv.diagnosis_key' => ['sometimes', 'nullable', 'string', 'max:24'],
            'isabv.diagnosis_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isabv.is_antibiogram_done' => ['sometimes', 'boolean'],
            'isabv.animals_count' => ['sometimes', 'nullable', 'integer', 'between:1,1000000'],
            'isabv.animals_total' => ['sometimes', 'nullable', 'integer', 'between:1,1000000'],
            'isabv.holding_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'isabv.holding_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isabv.av_treatment_days' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            'isabv.av_daily_dose' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            $linesKey.'.*.isabv' => ['sometimes', 'nullable', 'array'],
            $linesKey.'.*.isabv.route_code' => ['sometimes', 'nullable', 'string', 'max:4'],
            $linesKey.'.*.isabv.dose_1' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            $linesKey.'.*.isabv.frequency_1' => ['sometimes', 'nullable', 'string', 'max:8'],
            $linesKey.'.*.isabv.days_1' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            $linesKey.'.*.isabv.dose_2' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            $linesKey.'.*.isabv.frequency_2' => ['sometimes', 'nullable', 'string', 'max:8'],
            $linesKey.'.*.isabv.days_2' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            $linesKey.'.*.isabv.total_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            $linesKey.'.*.isabv.given_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            $linesKey.'.*.isabv.is_critical_confirmed' => ['sometimes', 'boolean'],
        ];
    }
}
