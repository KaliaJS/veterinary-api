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
            'ouverture' => '08:00',
            'fermeture' => '19:30',
            'fermeture_samedi' => '15:00',
            'samedi_ferme' => false,
            'dimanche_ferme' => true,
            'pause_active' => true,
            'pause_debut' => '12:30',
            'pause_fin' => '14:00',
        ];
    }
}
