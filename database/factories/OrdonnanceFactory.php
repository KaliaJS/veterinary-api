<?php

namespace Database\Factories;

use App\Enums\StatutOrdonnance;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Ordonnance;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ordonnance>
 */
class OrdonnanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'organisation_id' => fn (array $attributes) => Clinique::find($attributes['clinique_id'])->societe->organisation_id,
            'numero' => 'RX-'.now()->year.'-'.fake()->unique()->numerify('###'),
            'patient_id' => fn (array $attributes) => Patient::factory()->create([
                'organisation_id' => $attributes['organisation_id'],
            ])->id,
            'employe_id' => fn (array $attributes) => Employe::factory()->create([
                'clinique_id' => $attributes['clinique_id'],
            ])->id,
            'signee_le' => fake()->dateTimeBetween('-10 days'),
            'renouvellements' => fake()->numberBetween(0, 3),
            'statut' => StatutOrdonnance::Active,
            'note' => fake()->boolean(40) ? 'Réévaluation au prochain contrôle.' : null,
            'ordonnance_precedente_id' => null,
        ];
    }

    public function cloturee(): static
    {
        return $this->state(fn (): array => ['statut' => StatutOrdonnance::Cloturee]);
    }
}
