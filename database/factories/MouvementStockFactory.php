<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\MouvementStock;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MouvementStock>
 */
class MouvementStockFactory extends Factory
{
    public function definition(): array
    {
        $quantite = fake()->randomElement([-3, -2, -1, 1, 5, 10]);

        return [
            'produit_id' => Produit::factory(),
            'clinique_id' => Clinique::factory(),
            'user_id' => null,
            'quantite' => $quantite,
            'libelle' => $quantite > 0 ? 'Réception commande' : 'Sortie : consultation',
            'motif' => null,
            'created_at' => fake()->dateTimeBetween('-30 days'),
        ];
    }
}
