<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\OrganisationFacture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganisationFacture>
 */
class OrganisationFactureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'numero' => 'F-'.now()->year.'-'.fake()->unique()->numerify('###'),
            'emise_le' => fake()->dateTimeBetween('-4 months'),
            'libelle' => 'Abonnement mensuel',
            'montant' => 249,
        ];
    }
}
