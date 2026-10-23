<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\Societe;
use App\Rules\NumeroIde;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Societe>
 */
class SocieteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'nom' => fake()->company().' '.fake()->randomElement(['SA', 'Sàrl']),
            'ide' => self::ide(),
            // Compte de paiement ouvert et actif (passerelle factice dans les tests)
            'stripe_compte_id' => 'acct_test_'.fake()->unique()->bothify('????????'),
            'paiements_actifs' => true,
            'virements_actifs' => true,
        ];
    }

    /** Un IDE au chiffre de contrôle juste (NumeroIde). */
    public static function ide(): string
    {
        do {
            $base = sprintf('%08d', fake()->numberBetween(10000000, 99999999));
            $ide = sprintf('CHE-%s.%s.%s', substr($base, 0, 3), substr($base, 3, 3), substr($base, 6, 2));
            $candidat = null;
            foreach (range(0, 9) as $chiffre) {
                if (NumeroIde::valide($ide.$chiffre)) {
                    $candidat = $ide.$chiffre;
                }
            }
        } while ($candidat === null);

        return $candidat;
    }

    /** Société dont les paiements ne sont pas encore activés. */
    public function sansComptePaiement(): static
    {
        return $this->state(fn (): array => ['stripe_compte_id' => null, 'paiements_actifs' => false, 'virements_actifs' => false]);
    }
}
