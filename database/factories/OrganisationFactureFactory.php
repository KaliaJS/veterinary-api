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
            // Reflet d'une facture Stripe (numéro et identifiant Stripe)
            'stripe_facture_id' => 'in_'.fake()->unique()->bothify('????########'),
            'numero' => 'VET-'.fake()->unique()->numerify('####'),
            'emise_le' => fake()->dateTimeBetween('-4 months'),
            'libelle' => 'Formule Pro Cabinet, mensuelle',
            'statut' => 'payee',
            'montant' => 269.17,
            'montant_ht' => 249,
            'tva' => 20.17,
        ];
    }
}
