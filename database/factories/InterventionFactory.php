<?php

namespace Database\Factories;

use App\Enums\StatutIntervention;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Intervention;
use App\Models\Patient;
use App\Models\Salle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Intervention>
 */
class InterventionFactory extends Factory
{
    private const PROCEDURES = [
        'Ovariohystérectomie de convenance', 'Détartrage ultrasonique',
        'Exérèse masse cutanée', 'Cystotomie : retrait de calculs vésicaux',
        'Ostéosynthèse tibia',
    ];

    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'patient_id' => Patient::factory(),
            'salle_id' => fn (array $attributes) => Salle::factory()->bloc()->create(['clinique_id' => $attributes['clinique_id']])->id,
            'salle_nom' => fn (array $attributes) => Salle::find($attributes['salle_id'])->nom,
            'chirurgien_id' => fn (array $attributes) => Employe::factory()->dans($attributes['clinique_id'])->create()->id,
            'rendez_vous_id' => null,
            'procedure' => fake()->randomElement(self::PROCEDURES),
            'prevu_le' => fake()->dateTimeBetween('today 08:00', 'today 17:00'),
            'duree_minutes' => fake()->randomElement([45, 60, 75, 90, 120]),
            'statut' => StatutIntervention::Prevu,
            'protocole_anesthesique' => Intervention::PROTOCOLE_PAR_DEFAUT,
            'note_operatoire' => null,
            'incision_a' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Intervention $intervention): void {
            if ($intervention->checklist()->doesntExist()) {
                $intervention->creerChecklistDeBase();
            }
        });
    }

    /** Toute la checklist cochée — prête pour l'incision. */
    public function checklistComplete(): static
    {
        return $this->afterCreating(function (Intervention $intervention): void {
            $intervention->checklist()->update(['est_coche' => true]);
            $intervention->update(['consentement_signe_le' => now()]);
        });
    }

    public function finie(): static
    {
        return $this->state(fn (): array => [
            'statut' => StatutIntervention::Fini,
            'incision_a' => now()->subHours(2),
        ]);
    }
}
