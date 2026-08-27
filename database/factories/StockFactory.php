<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'produit_id' => Produit::factory(),
            'clinique_id' => Clinique::factory(),
            'quantite' => fake()->numberBetween(0, 30),
        ];
    }
}
