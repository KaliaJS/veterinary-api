<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    private const NOMS = [
        'Compte rendu chirurgie.pdf', 'Analyse sanguine.pdf',
        'Certificat de vaccination.pdf', 'Ordonnance.pdf',
        'Compte rendu imagerie.pdf', 'Carnet de santé.pdf',
    ];

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'nom' => fake()->randomElement(self::NOMS),
            'type' => null,
            'chemin' => null,
            'signataire' => null,
            'etabli_le' => fake()->dateTimeBetween('-14 months'),
        ];
    }
}
