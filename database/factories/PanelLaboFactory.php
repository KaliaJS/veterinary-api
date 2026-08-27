<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\PanelLabo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PanelLabo>
 */
class PanelLaboFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'code' => fake()->unique()->lexify('panel_????'),
            'nom' => 'Panel '.fake()->word(),
            'tube' => fake()->randomElement(['EDTA', 'SEC', 'HÉPARINE', 'CITRATE']),
        ];
    }
}
