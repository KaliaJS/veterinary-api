<?php

namespace Database\Factories;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\SessionTeleconsultation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionTeleconsultation>
 */
class SessionTeleconsultationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'rendez_vous_id' => null,
            'patient_id' => Patient::factory(),
            'motif' => 'Appel vidéo',
            'demarre_le' => now(),
            'demarre_par_id' => fn (array $attributes) => Employe::factory()->create(['clinique_id' => $attributes['clinique_id']])->id,
        ];
    }

    public function terminee(): static
    {
        return $this->state(fn (): array => [
            'demarre_le' => now()->subMinutes(12),
            'terminee_le' => now(),
            'duree_secondes' => 720,
        ]);
    }
}
