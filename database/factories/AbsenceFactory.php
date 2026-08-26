<?php

namespace Database\Factories;

use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Employe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Absence>
 */
class AbsenceFactory extends Factory
{
    public function definition(): array
    {
        $debut = fake()->dateTimeBetween('-1 month', '+1 month');

        return [
            'employe_id' => Employe::factory(),
            'type' => TypeAbsence::Conge,
            'debut_le' => $debut,
            'fin_le' => (clone $debut)->modify('+'.fake()->numberBetween(0, 6).' days'),
            'statut' => StatutAbsence::Validee,
            'note' => fake()->optional()->sentence(4),
            'valide_par_user_id' => null,
        ];
    }

    public function enAttente(): static
    {
        return $this->state(fn (): array => ['statut' => StatutAbsence::EnAttente]);
    }

    public function refusee(): static
    {
        return $this->state(fn (): array => ['statut' => StatutAbsence::Refusee]);
    }

    public function garde(): static
    {
        return $this->state(fn (): array => ['type' => TypeAbsence::Garde]);
    }
}
