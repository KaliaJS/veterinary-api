<?php

namespace Database\Factories;

use App\Enums\Civilite;
use App\Models\Client;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /** NPA + localité vaudois/genevois de la démo (owners.js). */
    private const LOCALITES = [
        ['1003', 'Lausanne'], ['1004', 'Lausanne'], ['1006', 'Lausanne'],
        ['1009', 'Pully'], ['1020', 'Renens'], ['1024', 'Ecublens'],
        ['1030', 'Bussigny'], ['1095', 'Lutry'], ['1110', 'Morges'],
        ['1260', 'Nyon'], ['1400', 'Yverdon-les-Bains'], ['1800', 'Vevey'],
    ];

    public function definition(): array
    {
        $civilite = fake()->randomElement(Civilite::cases());
        $prenom = fake()->firstName($civilite === Civilite::Madame ? 'female' : 'male');
        $nom = fake()->lastName();
        [$npa, $localite] = fake()->randomElement(self::LOCALITES);

        return [
            'organisation_id' => Organisation::factory(),
            'user_id' => null,
            'civilite' => $civilite,
            'prenom' => $prenom,
            'nom' => $nom,
            'tel' => '07'.fake()->numerify('# ### ## ##'),
            'email' => Str::lower(Str::ascii($nom)).'.'.fake()->randomElement(['famille', 'perso', 'pro']).'@'.fake()->randomElement(['gmail.com', 'bluewin.ch', 'outlook.fr']),
            'rue' => fake()->streetName().' '.fake()->numberBetween(1, 58),
            'npa' => $npa,
            'localite' => $localite,
        ];
    }
}
