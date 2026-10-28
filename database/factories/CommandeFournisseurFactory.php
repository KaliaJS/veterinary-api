<?php

namespace Database\Factories;

use App\Enums\StatutCommandeFournisseur;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommandeFournisseur>
 */
class CommandeFournisseurFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            // Moitié haute : jamais le numéro que le compteur donne ensuite dans un test (1, 2, 3…)
            'numero' => 'BC-'.now()->year.'-'.fake()->unique()->numberBetween(500, 999),
            'fournisseur' => fake()->randomElement(['Vetopharm SA', 'Zoevet AG', 'NutriVet Sàrl']),
            'statut' => StatutCommandeFournisseur::Brouillon,
            'envoyee_le' => null,
            'receptionnee_le' => null,
        ];
    }

    public function envoyee(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutCommandeFournisseur::Envoyee,
            'envoyee_le' => fake()->dateTimeBetween('-3 days'),
        ]);
    }

    public function receptionnee(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutCommandeFournisseur::Receptionnee,
            'envoyee_le' => fake()->dateTimeBetween('-6 days', '-2 days'),
            'receptionnee_le' => fake()->dateTimeBetween('-2 days'),
        ]);
    }
}
