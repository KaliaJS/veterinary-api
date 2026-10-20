<?php

namespace Database\Factories;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Par défaut une organisation ouverte : vérifiée, période payée en cours
 * (sans carte ni facture). `sansAbonnement()` et `enAttente()` pour la
 * facturation et l'inscription.
 *
 * @extends Factory<Organisation>
 */
class OrganisationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nom' => fake()->company().' Vétérinaires',
            'verification' => 'verifiee',
            'verifiee_le' => now(),
            'statut_abonnement' => 'actif',
            'periode_debut' => today(),
            'periode_fin' => today()->addMonth(),
            'ancre_jour' => today()->day,
        ];
    }

    /** Jamais abonnée : le premier abonnement reste à souscrire. */
    public function sansAbonnement(): static
    {
        return $this->state([
            'statut_abonnement' => null,
            'periode_debut' => null,
            'periode_fin' => null,
            'ancre_jour' => null,
        ]);
    }

    /** Inscrite, pas encore vérifiée. */
    public function enAttente(): static
    {
        return $this->sansAbonnement()->state(['verification' => 'en_attente', 'verifiee_le' => null]);
    }
}
