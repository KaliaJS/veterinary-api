<?php

namespace Database\Factories;

use App\Models\CommandeBoutique;
use App\Models\CommandeBoutiqueLigne;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommandeBoutiqueLigne>
 */
class CommandeBoutiqueLigneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'commande_boutique_id' => CommandeBoutique::factory(),
            'produit_id' => Produit::factory(),
            'quantite' => fake()->numberBetween(1, 3),
            'prix_chf' => fake()->randomFloat(2, 10, 60),
        ];
    }
}
