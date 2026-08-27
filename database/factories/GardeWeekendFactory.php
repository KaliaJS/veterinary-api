<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\GardeWeekend;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GardeWeekend>
 */
class GardeWeekendFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'samedi_le' => CarbonImmutable::today()->next(CarbonImmutable::SATURDAY)->addWeeks(fake()->numberBetween(0, 5)),
            'employe_id' => fn (array $attributes) => Employe::factory()->create([
                'clinique_id' => $attributes['clinique_id'],
            ])->id,
        ];
    }
}
