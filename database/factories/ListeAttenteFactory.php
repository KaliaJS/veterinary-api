<?php

namespace Database\Factories;

use App\Enums\Espece;
use App\Models\Clinique;
use App\Models\ListeAttente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListeAttente>
 */
class ListeAttenteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'patient_id' => null,
            'client_id' => null,
            'patient_name' => fake()->firstName(),
            'species' => fake()->randomElement([Espece::Chien, Espece::Chat, Espece::Lapin]),
            'owner_name' => fake()->randomElement(['M.', 'Mme']).' '.fake()->lastName(),
            'tel' => '07'.fake()->numerify('# ### ## ##'),
            'reason' => fake()->sentence(5),
            'preference' => fake()->randomElement(['Dès que possible', 'Le matin de préférence', 'Cette semaine']),
            'ligne_planning_id' => null,
            'duree_minutes' => fake()->randomElement([20, 30, 45]),
            'inscrit_le' => fake()->dateTimeBetween('-1 day'),
        ];
    }
}
