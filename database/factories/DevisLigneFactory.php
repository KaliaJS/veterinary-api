<?php

namespace Database\Factories;

use App\Enums\TypeLigneFacture;
use App\Models\Devis;
use App\Models\DevisLigne;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevisLigne>
 */
class DevisLigneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'devis_id' => Devis::factory(),
            'libelle' => fake()->randomElement(['Chirurgie — forfait bloc', 'Radiographie — 2 clichés', 'Détartrage + polissage']),
            'quantite' => 1,
            'prix_chf' => fake()->randomElement([120, 260, 420]),
            'type' => TypeLigneFacture::Acte,
            'produit_id' => null,
        ];
    }
}
