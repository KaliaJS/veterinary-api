<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\Societe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Societe>
 */
class SocieteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'nom' => fake()->company().' '.fake()->randomElement(['SA', 'Sàrl']),
            'ide' => sprintf('CHE-%03d.%03d.%03d', fake()->numberBetween(100, 999), fake()->numberBetween(100, 999), fake()->numberBetween(100, 999)),
        ];
    }
}
