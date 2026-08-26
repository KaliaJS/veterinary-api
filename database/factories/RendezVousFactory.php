<?php

namespace Database\Factories;

use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Clinique;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RendezVous>
 */
class RendezVousFactory extends Factory
{
    private const MOTIFS = [
        'Vaccination annuelle', 'Contrôle de croissance', 'Boiterie légère',
        'Otite externe', 'Bilan sanguin', 'Suivi de traitement',
        'Coupe des griffes', 'Diarrhée depuis 2 jours', 'Plaie superficielle',
        'Amaigrissement',
    ];

    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('today 08:00', 'today 18:00');

        return [
            'clinique_id' => Clinique::factory(),
            'ligne_planning_id' => fn (array $attributes) => LignePlanning::factory()->create(['clinique_id' => $attributes['clinique_id']])->id,
            'patient_id' => null,
            'client_id' => null,
            'salle_id' => null,
            'patient_name' => fake()->firstName(),
            'species' => fake()->randomElement([Espece::Chien, Espece::Chien, Espece::Chat]),
            'owner_name' => fake()->randomElement(['M.', 'Mme']).' '.fake()->lastName(),
            'type' => TypeRendezVous::Consultation,
            'status' => StatutRendezVous::Prevu,
            'flags' => [],
            'reason' => fake()->randomElement(self::MOTIFS),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+'.fake()->randomElement([20, 30, 45]).' minutes'),
            'arrived_at' => null,
        ];
    }

    /** Rattache le RDV à une fiche patient (FK + dénormalisations). */
    public function pourPatient(Patient $patient): static
    {
        return $this->state(fn (): array => [
            'patient_id' => $patient->id,
            'client_id' => $patient->client_id,
            'patient_name' => $patient->nom,
            'species' => $patient->espece,
            'owner_name' => $patient->client->nom_affiche,
        ]);
    }

    /** Créneau réservé sans patient (« RÉSERVÉ URGENCES », « CRÉNEAU LIBRE »). */
    public function tampon(): static
    {
        return $this->state(fn (): array => [
            'patient_id' => null,
            'client_id' => null,
            'patient_name' => null,
            'species' => null,
            'owner_name' => null,
            'type' => TypeRendezVous::Tampon,
            'reason' => null,
        ]);
    }

    public function annule(): static
    {
        return $this->state(fn (): array => ['status' => StatutRendezVous::Annule]);
    }

    public function enSalle(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StatutRendezVous::Salle,
            'arrived_at' => $attributes['starts_at'],
        ]);
    }
}
