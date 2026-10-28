<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\ClotureCaisse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClotureCaisse>
 */
class ClotureCaisseFactory extends Factory
{
    public function definition(): array
    {
        $especes = fake()->randomFloat(2, 50, 400);
        $fond = 200;

        return [
            'clinique_id' => Clinique::factory(),
            // Moitié haute : jamais le numéro que le compteur donne ensuite dans un test (1, 2, 3…)
            'numero' => 'Z-'.now()->year.'-'.fake()->unique()->numberBetween(500, 999),
            'arretee_le' => fake()->dateTimeBetween('-3 days'),
            'user_id' => null,
            'total_tpe' => fake()->randomFloat(2, 100, 900),
            'total_twint' => fake()->randomFloat(2, 0, 300),
            'total_especes' => $especes,
            'fond' => $fond,
            'especes_comptees' => $fond + $especes,
            'ecart' => 0,
            'depot' => $especes,
            'nouveau_fond' => $fond,
            'note' => null,
        ];
    }
}
