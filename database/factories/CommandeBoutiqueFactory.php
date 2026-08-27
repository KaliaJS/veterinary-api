<?php

namespace Database\Factories;

use App\Enums\StatutCommandeBoutique;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommandeBoutique>
 */
class CommandeBoutiqueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'numero' => 'C-'.fake()->unique()->numberBetween(100, 999),
            'client_id' => null,
            'client_nom' => fake()->firstName().' '.fake()->lastName(),
            'statut' => StatutCommandeBoutique::APreparer,
            'passee_le' => fake()->dateTimeBetween('-2 days'),
            'retiree_le' => null,
        ];
    }

    public function prete(): static
    {
        return $this->state(fn (): array => ['statut' => StatutCommandeBoutique::Prete]);
    }

    public function retiree(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutCommandeBoutique::Retiree,
            'retiree_le' => fake()->dateTimeBetween('-1 day'),
        ]);
    }
}
