<?php

namespace Database\Factories;

use App\Enums\ModaliteUrgences;
use App\Enums\ServiceClinique;
use App\Models\Clinique;
use App\Models\Societe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clinique>
 */
class CliniqueFactory extends Factory
{
    public function definition(): array
    {
        $ville = fake()->randomElement(['Lausanne', 'Genève', 'Morges', 'Vevey', 'Nyon', 'Yverdon-les-Bains']);

        return [
            'societe_id' => Societe::factory(),
            'nom' => 'Clinique '.fake()->unique()->lastName(),
            'raison_sociale' => 'Clinique Vétérinaire de '.$ville,
            'rue' => fake()->streetName().' '.fake()->numberBetween(1, 60),
            'npa' => (string) fake()->numberBetween(1000, 1999),
            'ville' => $ville,
            'latitude' => fake()->randomFloat(5, 46.1, 47.5),
            'longitude' => fake()->randomFloat(5, 6.1, 9.5),
            'tel' => '+41 21 '.fake()->numerify('### ## ##'),
            'fuseau_horaire' => 'Europe/Zurich',
            'nombre_boxes' => fake()->numberBetween(4, 10),
        ];
    }

    /**
     * Une clinique de test propose tous les services : les modules de
     * l'espace vétérinaire lui sont ouverts (voir `sansServices()`).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Clinique $clinique): void {
            foreach (ServiceClinique::cases() as $service) {
                $clinique->services()->create([
                    'service' => $service,
                    'modalite' => $service === ServiceClinique::Urgences ? ModaliteUrgences::Horaires : null,
                ]);
            }
        });
    }

    /** Clinique sans aucun service, comme une clinique créée par l'administrateur. */
    public function sansServices(): static
    {
        return $this->afterCreating(fn (Clinique $clinique) => $clinique->services()->delete());
    }
}
