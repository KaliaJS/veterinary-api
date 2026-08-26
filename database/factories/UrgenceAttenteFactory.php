<?php

namespace Database\Factories;

use App\Enums\Espece;
use App\Models\Clinique;
use App\Models\UrgenceAttente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UrgenceAttente>
 */
class UrgenceAttenteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'patient_id' => null,
            'client_id' => null,
            'patient_name' => fake()->firstName(),
            'species' => fake()->randomElement([Espece::Chien, Espece::Chat, Espece::Oiseau]),
            'owner_name' => fake()->randomElement(['M.', 'Mme']).' '.fake()->lastName(),
            'reason' => fake()->sentence(6),
            'triage' => fake()->numberBetween(1, 3),
            'duree_minutes' => fake()->randomElement([20, 30, 45]),
            'arrivee_le' => fake()->dateTimeBetween('-3 hours'),
        ];
    }
}
