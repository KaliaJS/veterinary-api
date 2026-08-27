<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MouvementStupefiant>
 */
class MouvementStupefiantFactory extends Factory
{
    /** Par défaut une réception (pas de patient) — cf. sortie(). */
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'produit_id' => Produit::factory()->stupefiant(),
            'patient_id' => null,
            'employe_id' => null,
            'quantite' => fake()->randomElement([10, 20, 50]),
            'motif' => 'Réception grossiste — lot '.fake()->bothify('??-####'),
            'created_at' => fake()->dateTimeBetween('-30 days'),
        ];
    }

    /** Administration à un patient — quantité négative, patient obligatoire. */
    public function sortie(): static
    {
        return $this->state(fn (): array => [
            'patient_id' => Patient::factory(),
            'quantite' => fake()->randomElement([-0.4, -0.6, -1.2]),
            'motif' => fake()->randomElement(['Induction anesthésie', 'Analgésie post-op']),
        ]);
    }
}
