<?php

namespace Database\Factories;

use App\Enums\StatutSejour;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\Sejour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sejour>
 */
class SejourFactory extends Factory
{
    private const MOTIFS = [
        'Post-op entérotomie (corps étranger)', 'Insuffisance rénale décompensée',
        'Surveillance post-anesthésie', 'Gastro-entérite hémorragique — réhydratation',
        'Pancréatite aiguë — fluides + analgésie', 'Équilibrage insuline (courbe de glycémie)',
    ];

    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'patient_id' => Patient::factory(),
            'box' => fake()->numberBetween(1, 6),
            'motif' => fake()->randomElement(self::MOTIFS),
            'statut' => StatutSejour::Stable,
            'veterinaire_id' => null,
            'admis_le' => now()->subHours(fake()->numberBetween(1, 30)),
            'sortie_prevue' => 'À définir',
            'sorti_le' => null,
            'perfusion' => null,
            'note' => null,
        ];
    }

    public function critique(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutSejour::Critique,
            'perfusion' => 'NaCl 0,9 % · 80 ml/h',
        ]);
    }

    public function sorti(): static
    {
        return $this->state(fn (): array => ['sorti_le' => now()]);
    }
}
