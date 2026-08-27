<?php

namespace Database\Factories;

use App\Enums\StatutDecompteAssurance;
use App\Models\DecompteAssurance;
use App\Models\Facture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DecompteAssurance>
 */
class DecompteAssuranceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'facture_id' => Facture::factory(),
            'assureur' => fake()->randomElement(['Animalia', 'Epona', 'SantéVet']),
            'statut' => StatutDecompteAssurance::AEnvoyer,
        ];
    }
}
