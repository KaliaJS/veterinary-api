<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\TerminalPaiement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Lecteur Stripe Terminal appairé à une clinique (passerelle factice dans
 * les tests). Le modèle n'a pas HasFactory : `TerminalPaiementFactory::new()`.
 *
 * @extends Factory<TerminalPaiement>
 */
class TerminalPaiementFactory extends Factory
{
    protected $model = TerminalPaiement::class;

    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'stripe_reader_id' => 'tmr_'.fake()->unique()->bothify('??????????????'),
            'libelle' => 'Comptoir',
            'modele' => 'bbpos_wisepos_e',
            'numero_serie' => null,
        ];
    }

    /** Lecteur simulé du mode test : `simuler-carte` y présente une carte. */
    public function simule(): static
    {
        return $this->state(['modele' => 'simulated_wisepos_e']);
    }
}
