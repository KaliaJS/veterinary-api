<?php

namespace Database\Factories;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\TerminalPaiement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tentative de paiement Stripe d'une facture : TWINT en attente par
 * défaut, sur la clinique d'émission de la facture. Montant fixe (les
 * tests le comparent). Le modèle n'a pas HasFactory :
 * `PaiementFactory::new()`.
 *
 * @extends Factory<Paiement>
 */
class PaiementFactory extends Factory
{
    protected $model = Paiement::class;

    public function definition(): array
    {
        return [
            'facture_id' => Facture::factory(),
            'clinique_id' => fn (array $attributs): int => Facture::withoutGlobalScopes()->findOrFail($attributs['facture_id'])->clinique_id,
            'moyen' => MoyenPaiement::Twint,
            'montant' => 85.5,
            'stripe_payment_intent_id' => 'pi_'.fake()->unique()->bothify('????????????????'),
            'statut' => StatutPaiement::EnAttente,
        ];
    }

    /** Carte présentée sur ce terminal (de la clinique du paiement). */
    public function surTerminal(TerminalPaiement $terminal): static
    {
        return $this->state([
            'moyen' => MoyenPaiement::Tpe,
            'clinique_id' => $terminal->clinique_id,
            'terminal_paiement_id' => $terminal->id,
        ]);
    }

    public function reussi(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutPaiement::Reussi,
            'reussi_le' => now(),
        ]);
    }
}
