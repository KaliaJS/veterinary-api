<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\Employe;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Employe>
 */
class EmployeFactory extends Factory
{
    public function definition(): array
    {
        $prenom = fake()->firstName();
        $nom = fake()->lastName();

        return [
            'clinique_id' => Clinique::factory(),
            'user_id' => null,
            'nom' => 'Dr '.$prenom.' '.$nom,
            'initiales' => Str::upper(Str::substr($prenom, 0, 1).Str::substr($nom, 0, 1)),
            'role' => 'Vétérinaire',
            'taux' => fake()->randomElement([60, 80, 100]),
            'contrat' => 'CDI',
            'entree_le' => fake()->dateTimeBetween('-8 years', '-3 months')->modify('first day of this month'),
            'tel' => '07'.fake()->numerify('# ### ## ##'),
            'email' => Str::lower(Str::substr($prenom, 0, 1).'.'.Str::ascii($nom)).'@clinique.ch',
            'conges_droit' => fake()->randomElement([20, 25]),
            'conges_pris' => fake()->numberBetween(0, 15),
        ];
    }

    public function asv(): static
    {
        return $this->state(function (): array {
            $prenom = fake()->firstName();
            $nom = fake()->lastName();

            return [
                'nom' => $prenom.' '.$nom,
                'initiales' => Str::upper(Str::substr($prenom, 0, 1).Str::substr($nom, 0, 1)),
                'role' => fake()->randomElement(['ASV', 'ASV · Accueil', 'ASV · Bloc']),
            ];
        });
    }
}
