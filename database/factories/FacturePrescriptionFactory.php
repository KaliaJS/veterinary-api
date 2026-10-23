<?php

namespace Database\Factories;

use App\Enums\ModePrescription;
use App\Models\Facture;
use App\Models\FacturePrescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacturePrescription>
 */
class FacturePrescriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'facture_id' => Facture::factory(),
            'nom' => 'Meloxicam 1,5 mg/ml',
            'posologie' => '0,1 ml / 10 kg, 1×/jour, 5 jours',
            'mode' => ModePrescription::Stock,
        ];
    }
}
