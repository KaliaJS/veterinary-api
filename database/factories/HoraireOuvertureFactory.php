<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\HoraireOuverture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Valeurs par défaut du front (docs/data-model.md §3 — Horaires).
 *
 * @extends Factory<HoraireOuverture>
 */
class HoraireOuvertureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            ...HoraireOuverture::DEFAUTS,
        ];
    }
}
