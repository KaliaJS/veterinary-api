<?php

namespace Database\Factories;

use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandeRendezVous>
 */
class DemandeRendezVousFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'patient_id' => null,
            'client_id' => null,
            'patient_name' => fake()->firstName(),
            'species' => fake()->randomElement([Espece::Chien, Espece::Chat]),
            'owner_name' => fake()->randomElement(['M.', 'Mme']).' '.fake()->lastName(),
            'reason' => fake()->sentence(6),
            'souhait' => fake()->randomElement(['Aujourd’hui après 16:00', 'Cette semaine, le matin', 'Dès que possible']),
            'mode' => ModeDemandeRendezVous::AValider,
            'duree_minutes' => fake()->randomElement([20, 30]),
        ];
    }
}
