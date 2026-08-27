<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\Prestation;
use App\Services\PrestationsParDefaut;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prestation>
 */
class PrestationFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->unique()->randomElement(array_keys(PrestationsParDefaut::CATALOGUE));
        [$nom, $prix] = PrestationsParDefaut::CATALOGUE[$code];

        return [
            'organisation_id' => Organisation::factory(),
            'code' => $code,
            'nom' => $nom,
            'prix' => $prix,
        ];
    }
}
