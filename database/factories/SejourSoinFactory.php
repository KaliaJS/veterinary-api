<?php

namespace Database\Factories;

use App\Models\Sejour;
use App\Models\SejourSoin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SejourSoin>
 */
class SejourSoinFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sejour_id' => Sejour::factory(),
            'prevu_a' => fake()->numberBetween(0, 47) * 30,
            'acte' => fake()->randomElement([
                'Contrôle T° / constantes', 'Contrôle perfusion / hydratation',
                'Proposition repas + sortie hygiénique', 'Contrôle pansement',
            ]),
            'produit_id' => null,
            'fait' => false,
        ];
    }

    public function fait(): static
    {
        return $this->state(fn (): array => ['fait' => true, 'fait_le' => now()]);
    }
}
