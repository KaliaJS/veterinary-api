<?php

namespace Database\Factories;

use App\Enums\TypeLigneFacture;
use App\Models\Facture;
use App\Models\FactureLigne;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FactureLigne>
 */
class FactureLigneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'facture_id' => Facture::factory(),
            'libelle' => fake()->randomElement(['Consultation standard', 'Vaccination — acte', 'Contrôle post-opératoire']),
            'quantite' => 1,
            'prix_chf' => fake()->randomElement([45, 55, 85, 140]),
            'type' => TypeLigneFacture::Acte,
            'produit_id' => null,
        ];
    }
}
