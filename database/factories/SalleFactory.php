<?php

namespace Database\Factories;

use App\Enums\TypeSalle;
use App\Models\Clinique;
use App\Models\Salle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Salle>
 */
class SalleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'nom' => 'Consultation '.fake()->numberBetween(1, 3),
            'type' => TypeSalle::Consultation,
        ];
    }

    public function bloc(): static
    {
        return $this->state(fn (): array => [
            'nom' => 'Bloc '.fake()->numberBetween(1, 2),
            'type' => TypeSalle::Bloc,
        ]);
    }

    public function imagerie(): static
    {
        return $this->state(fn (): array => [
            'nom' => 'Radio / Écho',
            'type' => TypeSalle::Imagerie,
        ]);
    }
}
