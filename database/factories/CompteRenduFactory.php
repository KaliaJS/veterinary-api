<?php

namespace Database\Factories;

use App\Models\CompteRendu;
use App\Models\RendezVous;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompteRendu>
 */
class CompteRenduFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rendez_vous_id' => RendezVous::factory(),
            's' => 'Boiterie postérieure apparue il y a trois jours, sans traumatisme rapporté.',
            'o' => 'Douleur à la palpation du grasset droit, pas de laxité, T° 38,4 °C.',
            'a' => 'Suspicion d’entorse du ligament collatéral, radiographie à prévoir si persistance.',
            'p' => 'Repos strict 10 jours, méloxicam 7 jours, contrôle à J10.',
            'user_id' => null,
        ];
    }
}
