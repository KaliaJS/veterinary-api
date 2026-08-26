<?php

namespace Database\Factories;

use App\Enums\ResultatFluoresceine;
use App\Models\Patient;
use App\Models\ReleveOphtalmologique;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleveOphtalmologique>
 */
class ReleveOphtalmologiqueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'releve_le' => fake()->dateTimeBetween('-1 year'),
            'schirmer_od' => fake()->numberBetween(10, 25),
            'schirmer_og' => fake()->numberBetween(10, 25),
            'pio_od' => fake()->numberBetween(10, 25),
            'pio_og' => fake()->numberBetween(10, 25),
            'fluoresceine' => fake()->boolean(85) ? ResultatFluoresceine::Negatif : ResultatFluoresceine::Positif,
            'notes' => fake()->optional()->sentence(6),
        ];
    }
}
