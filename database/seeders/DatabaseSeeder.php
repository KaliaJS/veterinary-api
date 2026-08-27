<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Jeu de démo aligné sur le front (../veterinary-web) : mêmes
     * cliniques, même équipe, mêmes rendez-vous du jour — le front,
     * complet sur données seedées, doit retrouver son planning.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Anna Meyer',
            'email' => 'a.meyer@deuxrives.ch',
        ]);

        $this->call([
            CliniqueSeeder::class,
            EquipeSeeder::class,
            PatientSeeder::class,
            RendezVousSeeder::class,
            ProduitSeeder::class,
            OrdonnanceSeeder::class,
        ]);
    }
}
