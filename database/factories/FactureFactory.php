<?php

namespace Database\Factories;

use App\Enums\MoyenPaiement;
use App\Enums\StatutFacture;
use App\Models\Clinique;
use App\Models\Facture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facture>
 */
class FactureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'organisation_id' => fn (array $attributes) => Clinique::find($attributes['clinique_id'])->societe->organisation_id,
            // Moitié haute : jamais le numéro que le compteur donne ensuite dans un test (1, 2, 3…)
            'numero' => 'FC-'.now()->year.'-'.fake()->unique()->numberBetween(5000, 9999),
            'emise_le' => fake()->dateTimeBetween('-5 days'),
            'patient_name' => fake()->firstName(),
            'species' => fake()->randomElement(['CH', 'CT']),
            'owner_name' => 'M. '.fake()->lastName(),
            'statut' => StatutFacture::Due,
        ];
    }

    public function payee(?MoyenPaiement $moyen = null): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutFacture::Payee,
            'moyen_paiement' => $moyen ?? fake()->randomElement(MoyenPaiement::cases()),
            'payee_le' => fake()->dateTimeBetween('-2 days'),
            'stock_sorti' => true,
        ]);
    }
}
