<?php

namespace Database\Factories;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Devis>
 */
class DevisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organisation_id' => fn (): int => Clinique::factory()->create()->societe->organisation_id,
            // Une clinique de l'organisation du devis (la première), sinon une nouvelle
            'clinique_id' => fn (array $attributs): int => Clinique::query()
                ->whereHas('societe', fn ($societe) => $societe->where('organisation_id', $attributs['organisation_id']))
                ->orderBy('id')
                ->value('id') ?? Clinique::factory()->create()->id,
            'numero' => 'DV-'.now()->year.'-'.fake()->unique()->numerify('###'),
            'emis_le' => fake()->dateTimeBetween('-10 days'),
            'patient_id' => fn (array $attributes) => Patient::factory()->create([
                'organisation_id' => $attributes['organisation_id'],
            ])->id,
            'motif' => fake()->randomElement([
                'Ostéosynthèse fémur droit',
                'Détartrage + polissage sous anesthésie',
                'Bilan boiterie : imagerie + consultation',
            ]),
            'statut' => StatutDevis::Brouillon,
        ];
    }

    public function envoye(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutDevis::Envoye,
            'canal' => CanalDevis::Papier,
            'valide_jusquau' => now()->addDays(30),
        ]);
    }

    public function accepte(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutDevis::Accepte,
            'canal' => CanalDevis::Papier,
            'valide_jusquau' => now()->addDays(28),
            'signe_le' => now()->subDay(),
            'signe_par' => 'Signature papier « bon pour accord », classée au dossier',
            'acompte_pct' => 30,
        ]);
    }

    public function expire(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutDevis::Envoye,
            'canal' => CanalDevis::Papier,
            'valide_jusquau' => now()->subDays(5),
        ]);
    }
}
