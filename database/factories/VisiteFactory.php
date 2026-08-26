<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Visite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visite>
 */
class VisiteFactory extends Factory
{
    private const MOTIFS = [
        'Vaccination annuelle', 'Contrôle général', 'Boiterie', 'Dermatite',
        'Détartrage', 'Otite', 'Suivi post-opératoire', 'Vomissements',
        'Bilan senior', 'Coupe de griffes', 'Abcès', 'Perte d’appétit',
    ];

    private const NOTES = [
        'RAS, état général satisfaisant.',
        'Amélioration nette depuis la dernière visite.',
        'À revoir sous 3 semaines si persistance.',
        'Prescription renouvelée, posologie inchangée.',
        'Propriétaire informé du protocole à suivre.',
        'Légère perte de poids à surveiller.',
    ];

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'visite_le' => fake()->dateTimeBetween('-2 years'),
            'motif' => fake()->randomElement(self::MOTIFS),
            'employe_id' => null,
            'note' => fake()->randomElement(self::NOTES),
        ];
    }
}
