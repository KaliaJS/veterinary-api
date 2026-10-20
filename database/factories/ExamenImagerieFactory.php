<?php

namespace Database\Factories;

use App\Enums\ModaliteImagerie;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamenImagerie>
 */
class ExamenImagerieFactory extends Factory
{
    private const array EXAMENS = [
        ['Radiographie thorax', 'Profil droit · Face'],
        ['Radiographie abdomen', 'Face (VD) · Profil'],
        ['Radiographie membre', 'Face + profil'],
    ];

    public function definition(): array
    {
        $examen = fake()->randomElement(self::EXAMENS);

        return [
            'clinique_id' => Clinique::factory(),
            'patient_id' => Patient::factory(),
            'rendez_vous_id' => null,
            'modalite' => ModaliteImagerie::Radiographie,
            'libelle' => $examen[0],
            'incidence' => $examen[1],
            'realise_le' => fake()->dateTimeBetween('-30 days'),
            'duree_minutes' => 30,
            'prescripteur_id' => fn (array $attributes) => Employe::factory()->dans($attributes['clinique_id'])->create()->id,
        ];
    }

    /** Compte rendu signé — l'examen est figé. */
    public function signe(): static
    {
        return $this->state(fn (): array => [
            'constatations' => 'Cliché de qualité diagnostique. Examen dans les limites de la normale.',
            'conclusion' => 'Examen dans les limites de la normale.',
            'signe_par_id' => fn (array $attributes) => Employe::factory()->dans($attributes['clinique_id'])->create()->id,
            'signe_le' => now(),
        ]);
    }
}
