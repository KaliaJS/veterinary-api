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
            // Compte de paiement ouvert et actif (passerelle factice dans les tests)
            'stripe_compte_id' => 'acct_test_'.fake()->unique()->bothify('????????'),
            'paiements_actifs' => true,
            'virements_actifs' => true,
        ];
    }

    /** Société dont les paiements ne sont pas encore activés. */
    public function sansComptePaiement(): static
    {
        return $this->state(fn (): array => ['stripe_compte_id' => null, 'paiements_actifs' => false, 'virements_actifs' => false]);
    }
}
