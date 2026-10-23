<?php

namespace Database\Factories;

use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdonnanceLigne>
 */
class OrdonnanceLigneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ordonnance_id' => Ordonnance::factory(),
            'produit_id' => Produit::factory()->medicament(),
            'posologie' => fake()->randomElement([
                '0,1 mg/kg : 1× par jour, pendant le repas',
                '1 comprimé matin et soir',
                '2 UI en injection sous-cutanée, 2× par jour',
            ]),
            'duree' => fake()->randomElement(['7 jours', '10 jours', '28 jours', 'Selon besoin']),
        ];
    }
}
