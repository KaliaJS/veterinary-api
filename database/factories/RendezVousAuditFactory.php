<?php

namespace Database\Factories;

use App\Enums\StatutRendezVous;
use App\Models\RendezVous;
use App\Models\RendezVousAudit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RendezVousAudit>
 */
class RendezVousAuditFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rendez_vous_id' => RendezVous::factory(),
            'user_id' => null,
            'batch_id' => (string) Str::ulid(),
            'champ' => 'status',
            'ancienne_valeur' => StatutRendezVous::Prevu->value,
            'nouvelle_valeur' => StatutRendezVous::Salle->value,
            'created_at' => now(),
        ];
    }
}
