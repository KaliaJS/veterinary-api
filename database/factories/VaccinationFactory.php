<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Vaccination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vaccination>
 */
class VaccinationFactory extends Factory
{
    private const VACCINS = [
        ['Carré / Parvovirose', 'Nobivac DHPPi', 1],
        ['Leptospirose', 'Versican Plus L4', 1],
        ['Rage', 'Rabisin', 3],
        ['Typhus / Coryza', 'Purevax RCP', 1],
        ['Leucose (FeLV)', 'Purevax FeLV', 1],
        ['VHD', 'Filavac VHD K C+V', 1],
        ['Myxomatose', 'Nobivac Myxo-RHD Plus', 1],
    ];

    public function definition(): array
    {
        [$nom, $marque, $annees] = fake()->randomElement(self::VACCINS);
        $injectee = fake()->dateTimeBetween('-14 months', '-1 month');

        return [
            'patient_id' => Patient::factory(),
            'nom' => $nom,
            'marque' => $marque,
            'lot' => 'L'.fake()->numberBetween(100, 999).'-'.fake()->randomLetter().fake()->numberBetween(10, 99),
            'employe_id' => null,
            'injectee_le' => $injectee,
            'echeance_le' => (clone $injectee)->modify("+{$annees} year"),
        ];
    }
}
