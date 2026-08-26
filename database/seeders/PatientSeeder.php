<?php

namespace Database\Seeders;

use App\Enums\Espece;
use App\Enums\Sexe;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\Vaccination;
use App\Models\Visite;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Référentiel patients/propriétaires de la démo (patients.seed.js) :
 * mêmes noms d'animaux — dont ceux des RDV du jour, avec l'espèce vue
 * au planning — et foyers multi-animaux (un client possède plusieurs
 * patients). Les valeurs non significatives sont tirées au hasard.
 */
class PatientSeeder extends Seeder
{
    use WithoutModelEvents;

    private const NOMS = [
        'Nala', 'Gaspard', 'Zia', 'Praline', 'Bagheera', 'Chaussette', 'Java', 'Ficelle',
        'Cannelle', 'Sushi', 'Iroise', 'Pistache', 'Bourbon', 'Écorce', 'Pixel', 'Loki',
        'Biscuit', 'Orion', 'Nino', 'Pilou', 'Ourson', 'Prune', 'Ramen', 'Câline', 'Tofu',
        'Tempête', 'Mistral', 'Nougat', 'Kito', 'Yuki', 'Silva', 'Mochi', 'Tsuki', 'Isis',
        'Vega', 'Gribouille', 'Pépite', 'Kobe', 'Câlin', 'Nuage', 'Zélie', 'Ozzy', 'Suki',
        'Rocket', 'Perle', 'Ilo', 'Fripon', 'Neige', 'Pacha', 'Tao', 'Sirius', 'Boubou',
        'Ginger', 'Malo', 'Bandit', 'Milo', 'Olive', 'Charlie', 'Micha', 'Plume', 'Réglisse',
        'Moka', 'Filou', 'Hermine', 'Jazz', 'Kiwi', 'Luna', 'Maya', 'Noisette', 'Opale',
    ];

    /**
     * Espèce imposée par les RDV du jour (planning.seed.js) — la fiche
     * doit correspondre à ce que le planning affiche.
     */
    private const ESPECES_IMPOSEES = [
        'Nala' => 'CT', 'Gaspard' => 'CH', 'Zia' => 'CH', 'Praline' => 'CT',
        'Chaussette' => 'CH', 'Java' => 'CH', 'Ficelle' => 'CH', 'Cannelle' => 'CH',
        'Sushi' => 'CT', 'Iroise' => 'CH', 'Filou' => 'CH', 'Pistache' => 'CT',
        'Bourbon' => 'CH', 'Écorce' => 'CH', 'Pixel' => 'LP', 'Loki' => 'CH',
        'Biscuit' => 'FU', 'Orion' => 'CH', 'Nino' => 'CH', 'Pilou' => 'CH',
        'Ourson' => 'CH', 'Prune' => 'CH', 'Ramen' => 'CH', 'Câline' => 'CT',
        'Tofu' => 'CT', 'Tempête' => 'CT', 'Mistral' => 'CH', 'Bagheera' => 'CH',
        'Nougat' => 'CH', 'Kito' => 'CH', 'Yuki' => 'CT', 'Silva' => 'CH',
        'Mochi' => 'CT', 'Tsuki' => 'CH', 'Isis' => 'CH', 'Vega' => 'CT',
        'Gribouille' => 'CH', 'Pépite' => 'CT', 'Kobe' => 'CH', 'Câlin' => 'CH',
        'Nuage' => 'CT', 'Opale' => 'CT', 'Perle' => 'CT', 'Plume' => 'OI',
        'Réglisse' => 'CT',
    ];

    private const PROPRIETAIRES = [
        'Martin', 'Lefebvre', 'Garcia', 'Rousseau', 'Petit', 'Moreau', 'Fournier', 'Dubois',
        'Lambert', 'Mercier', 'Blanc', 'Henry', 'Faure', 'Roux', 'Caron', 'Marchand',
        'Duval', 'Gauthier', 'Perrin', 'Chevalier', 'Ndiaye', 'Cohen', 'Silva', 'Costa',
        'Meunier', 'Barbier', 'Ferry', 'Weiss', 'Morel', 'Adam', 'Aubry', 'Benoit',
        'Colin', 'Daniel', 'Fischer', 'Gérard', 'Hubert', 'Joly', 'Klein', 'Leroy',
        'Masson', 'Noël', 'Olivier', 'Renard', 'Simon',
    ];

    /** Protocoles vaccinaux par espèce : [maladies, marque, périodicité en années]. */
    private const PROTOCOLES = [
        'CH' => [['Rage', 'Rabisin', 3], ['Carré / Parvovirose', 'Nobivac DHPPi', 1], ['Leptospirose', 'Versican Plus L4', 1]],
        'CT' => [['Typhus / Coryza', 'Purevax RCP', 1], ['Leucose (FeLV)', 'Purevax FeLV', 1], ['Rage', 'Rabisin', 3]],
        'LP' => [['VHD', 'Filavac VHD K C+V', 1], ['Myxomatose', 'Nobivac Myxo-RHD Plus', 1]],
        'FU' => [['Maladie de Carré', 'Nobivac D', 1]],
        'RG' => [['Bilan annuel', null, 1]],
        'OI' => [['Bilan annuel', null, 1]],
        'RP' => [['Bilan annuel', null, 1]],
    ];

    public function run(): void
    {
        $organisation = Organisation::firstOrFail();

        /** @var array<string, Client> $clients */
        $clients = [];

        foreach (self::NOMS as $nomAnimal) {
            $nomProprietaire = fake()->randomElement(self::PROPRIETAIRES);
            $clients[$nomProprietaire] ??= Client::factory()->create([
                'organisation_id' => $organisation->id,
                'nom' => $nomProprietaire,
            ]);
            $client = $clients[$nomProprietaire];

            $espece = isset(self::ESPECES_IMPOSEES[$nomAnimal])
                ? Espece::from(self::ESPECES_IMPOSEES[$nomAnimal])
                : null;

            $patient = Patient::factory()
                ->for($client)
                ->create(array_filter([
                    'organisation_id' => $organisation->id,
                    'nom' => $nomAnimal,
                    'espece' => $espece,
                    'sexe' => fake()->randomElement(Sexe::cases()),
                ], fn ($valeur) => $valeur !== null));

            $this->seedDossier($patient);
        }
    }

    private function seedDossier(Patient $patient): void
    {
        // Historique de poids : 8 pesées espacées de 3 mois, dérive ±5 %.
        $poids = match ($patient->espece) {
            Espece::Chien => fake()->randomFloat(2, 4, 34),
            Espece::Chat => fake()->randomFloat(2, 2.8, 6.4),
            default => fake()->randomFloat(2, 0.3, 2.5),
        };
        for ($i = 7; $i >= 0; $i--) {
            Pesee::create([
                'patient_id' => $patient->id,
                'poids_kg' => round($poids, 2),
                'pesee_le' => today()->subMonths($i * 3),
            ]);
            $poids *= 1 + fake()->randomFloat(3, -0.024, 0.026);
        }

        foreach (self::PROTOCOLES[$patient->espece->value] as [$maladies, $marque, $annees]) {
            $injectee = today()->subDays(fake()->numberBetween(100, 430));
            Vaccination::create([
                'patient_id' => $patient->id,
                'nom' => $maladies,
                'marque' => $marque,
                'lot' => $marque ? 'L'.fake()->numberBetween(100, 999).'-'.strtoupper(fake()->randomLetter()).fake()->numberBetween(10, 99) : null,
                'injectee_le' => $injectee,
                'echeance_le' => $injectee->copy()->addYears($annees),
            ]);
        }

        $visiteLe = today()->subDays(fake()->numberBetween(3, 43));
        foreach (range(1, fake()->numberBetween(2, 5)) as $i) {
            Visite::factory()->create([
                'patient_id' => $patient->id,
                'visite_le' => $visiteLe,
            ]);
            $visiteLe = $visiteLe->copy()->subDays(fake()->numberBetween(30, 190));
        }

        Document::factory()
            ->count(fake()->numberBetween(1, 3))
            ->create(['patient_id' => $patient->id]);
    }
}
