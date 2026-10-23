<?php

namespace Database\Factories;

use App\Enums\Espece;
use App\Enums\Sexe;
use App\Models\Client;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    private const NOMS = [
        'Nala', 'Gaspard', 'Zia', 'Praline', 'Bagheera', 'Chaussette', 'Java', 'Ficelle',
        'Cannelle', 'Sushi', 'Iroise', 'Pistache', 'Bourbon', 'Écorce', 'Pixel', 'Loki',
        'Biscuit', 'Orion', 'Nino', 'Pilou', 'Ourson', 'Prune', 'Ramen', 'Câline', 'Tofu',
        'Tempête', 'Mistral', 'Nougat', 'Kito', 'Yuki', 'Silva', 'Mochi', 'Tsuki', 'Isis',
        'Vega', 'Gribouille', 'Pépite', 'Kobe', 'Câlin', 'Nuage', 'Moka', 'Filou', 'Luna',
    ];

    private const RACES = [
        'CH' => ['Berger australien', 'Labrador', 'Border collie', 'Beagle', 'Cavalier King Charles', 'Jack Russell', 'Golden retriever', 'Bouledogue français', 'Croisé'],
        'CT' => ['Européen', 'Maine coon', 'Chartreux', 'Siamois', 'Bengal', 'Sacré de Birmanie', 'Croisé'],
        'LP' => ['Lapin nain', 'Bélier nain'],
        'FU' => ['Furet'],
        'RG' => ['Cochon d’Inde', 'Rat domestique', 'Hamster'],
        'OI' => ['Perruche calopsitte', 'Canari'],
        'RP' => ['Tortue d’Hermann', 'Gecko léopard'],
    ];

    private const TRAITEMENTS = [
        'Meloxicam 1,5 mg : 1 prise/jour pendant 7 jours',
        'Amoxicilline 250 mg : 2 prises/jour pendant 10 jours',
        'Antiparasitaire mensuel : prochaine prise fin de mois',
        'Alimentation rénale stricte : contrôle dans 3 mois',
    ];

    public function definition(): array
    {
        $r = fake()->randomFloat(2, 0, 1);
        $espece = $r < 0.48 ? Espece::Chien : ($r < 0.84 ? Espece::Chat : fake()->randomElement([Espece::Lapin, Espece::Furet, Espece::Rongeur, Espece::Oiseau, Espece::Reptile]));
        $ageMax = $espece === Espece::Chien ? 13 : ($espece === Espece::Chat ? 15 : 7);

        return [
            'client_id' => Client::factory(),
            'organisation_id' => fn (array $attributes) => Client::find($attributes['client_id'])->organisation_id,
            'nom' => fake()->randomElement(self::NOMS),
            'espece' => $espece,
            'race' => fn (array $attributes) => fake()->randomElement(
                self::RACES[$attributes['espece'] instanceof Espece ? $attributes['espece']->value : $attributes['espece']]
            ),
            'sexe' => fake()->randomElement(Sexe::cases()),
            'ne_le' => fake()->dateTimeBetween("-{$ageMax} years", '-1 year'),
            'est_sterilise' => fake()->boolean(62),
            'puce' => $this->numeroPuce(),
            'dette' => fake()->boolean(9) ? fake()->numberBetween(2, 19) * 10 : 0,
            'est_agressif' => fake()->boolean(11),
            'a_allergies' => fake()->boolean(12),
            'traitement_en_cours' => fake()->boolean(40) ? fake()->randomElement(self::TRAITEMENTS) : null,
            'rappels_coupes' => false,
            'amicus_declare_le' => null,
            'client_depuis' => fake()->numberBetween(2015, (int) date('Y')),
        ];
    }

    /** N° de puce au format suisse AMICUS « 756 09X XXX XXX XXX ». */
    private function numeroPuce(): string
    {
        $d9 = (string) fake()->numberBetween(100000000, 999999999);

        return '756 09'.fake()->numberBetween(0, 9).' '.substr($d9, 0, 3).' '.substr($d9, 3, 3).' '.substr($d9, 6, 3);
    }

    public function declareAmicus(): static
    {
        return $this->state(fn (): array => [
            'espece' => Espece::Chien,
            'amicus_declare_le' => fake()->dateTimeBetween('-2 years'),
        ]);
    }
}
