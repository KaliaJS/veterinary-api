<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Pesee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pesee>
 */
class PeseeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'poids_kg' => fake()->randomFloat(2, 0.4, 38),
            'pesee_le' => fake()->dateTimeBetween('-2 years'),
        ];
    }
}
