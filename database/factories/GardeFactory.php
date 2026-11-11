<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Garde;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Garde>
 */
class GardeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'jour' => CarbonImmutable::today()->addDays(fake()->numberBetween(0, 40)),
            'employe_id' => fn (array $attributes) => Employe::factory()->dans($attributes['clinique_id'])->create(['role_libelle' => 'Vétérinaire'])->id,
        ];
    }
}
