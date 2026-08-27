<?php

namespace Database\Factories;

use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommandeFournisseurLigne>
 */
class CommandeFournisseurLigneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'commande_fournisseur_id' => CommandeFournisseur::factory(),
            'produit_id' => Produit::factory(),
            'quantite' => fake()->numberBetween(2, 20),
            'cout' => fake()->randomFloat(2, 4, 45),
        ];
    }
}
