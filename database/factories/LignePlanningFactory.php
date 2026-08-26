<?php

namespace Database\Factories;

use App\Enums\RoleLignePlanning;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\LignePlanning;
use App\Models\Salle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Par défaut, une ligne de consultation portée par un praticien de la
 * même clinique ; `bloc()` et `imagerie()` créent une ligne-ressource.
 *
 * @extends Factory<LignePlanning>
 */
class LignePlanningFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'employe_id' => fn (array $attributes) => Employe::factory()->create(['clinique_id' => $attributes['clinique_id']])->id,
            'salle_id' => null,
            'nom' => fn (array $attributes) => Employe::find($attributes['employe_id'])->nom,
            'sous_titre' => 'CONSULTATION',
            'role' => RoleLignePlanning::Consultation,
            'initiales' => fn (array $attributes) => Employe::find($attributes['employe_id'])->initiales,
            'groupe' => 'Consultation',
            'ordre' => 0,
        ];
    }

    public function bloc(): static
    {
        return $this->state(fn (): array => [
            'employe_id' => null,
            'salle_id' => fn (array $attributes) => Salle::factory()->bloc()->create(['clinique_id' => $attributes['clinique_id']])->id,
            'nom' => 'Bloc 1',
            'sous_titre' => 'CHIRURGIE',
            'role' => RoleLignePlanning::Bloc,
            'initiales' => 'B1',
            'groupe' => 'Chirurgie',
        ]);
    }

    public function imagerie(): static
    {
        return $this->state(fn (): array => [
            'employe_id' => null,
            'salle_id' => fn (array $attributes) => Salle::factory()->imagerie()->create(['clinique_id' => $attributes['clinique_id']])->id,
            'nom' => 'Radio / Écho',
            'sous_titre' => 'IMAGERIE',
            'role' => RoleLignePlanning::Imagerie,
            'initiales' => 'IM',
            'groupe' => 'Imagerie',
        ]);
    }
}
