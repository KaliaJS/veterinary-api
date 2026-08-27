<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\Lot;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'produit_id' => Produit::factory(),
            'clinique_id' => Clinique::factory(),
            'numero_lot' => 'LOT '.fake()->numerify('##?###'),
            'peremption_le' => fake()->dateTimeBetween('+1 month', '+18 months')->modify('first day of this month'),
            'quantite' => fake()->numberBetween(1, 15),
        ];
    }

    public function perime(): static
    {
        return $this->state(fn (): array => [
            'peremption_le' => fake()->dateTimeBetween('-6 months', '-1 month')->modify('first day of this month'),
        ]);
    }
}
