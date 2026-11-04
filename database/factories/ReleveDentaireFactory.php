<?php

namespace Database\Factories;

use App\Enums\EtatDent;
use App\Models\Patient;
use App\Models\ReleveDentaire;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleveDentaire>
 */
class ReleveDentaireFactory extends Factory
{
    public function definition(): array
    {
        // Numérotation Triadan : quadrants 1–4, positions 01–11.
        $dent = fake()->numberBetween(1, 4) * 100 + fake()->numberBetween(1, 11);

        return [
            'patient_id' => Patient::factory(),
            'dent' => $dent,
            // Un ou deux états à la fois (04.11.2026)
            'etats' => array_values(array_unique(array_map(fn (EtatDent $etat) => $etat->value, fake()->randomElements(EtatDent::cases(), fake()->numberBetween(1, 2))))),
        ];
    }
}
