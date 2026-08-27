<?php

namespace Database\Factories;

use App\Models\Sejour;
use App\Models\SejourConstante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SejourConstante>
 */
class SejourConstanteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sejour_id' => Sejour::factory(),
            'mesure_a' => fake()->numberBetween(16, 44) * 30,
            'temperature' => fake()->randomFloat(1, 37.5, 39.5),
            'fc' => fake()->numberBetween(70, 130),
            'fr' => fake()->numberBetween(16, 36),
        ];
    }
}
