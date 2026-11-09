<?php

namespace Database\Seeders;

use App\Models\Employe;
use App\Models\User;
use App\Services\Facturation\PatientDebts;
use App\Services\Facturation\Settlements;
use App\Support\BackfillRecordSources;
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
            StupefiantSeeder::class,
            OrdonnanceSeeder::class,
            FacturationSeeder::class,
            BoutiqueSeeder::class,
            HospitalisationSeeder::class,
            BlocSeeder::class,
            LaboratoireSeeder::class,
            ImagerieSeeder::class,
            SiteWebSeeder::class,
            AnnuaireSeeder::class,
            // Après toutes les cliniques et leurs équipes
            ServicesCliniquesSeeder::class,
            // Des heures déjà prises dans le calendrier de la prise de rendez-vous
            RendezVousAVenirSeeder::class,
        ]);

        // Démonstration : les personnes (fictives) des équipes acceptent de
        // paraître sur les sites publics (accord nLPD, 23.10.2026)
        Employe::withoutGlobalScopes()->update(['accord_site_le' => now()]);

        // Les actes de la démo, créés sans clinique en cours : ceux des
        // animaux de clinique prennent l'organisation du dossier (provenance)
        app(BackfillRecordSources::class)->run();

        // Le seed passe sans hooks de modèle : les règlements des factures
        // payées et des acomptes se posent une fois à la fin (09.11.2026)
        app(Settlements::class)->backfillAll();

        // Le seed passe sans hooks de modèle : la dette des animaux se calcule
        // une fois à la fin, depuis les factures dues (03.11.2026)
        PatientDebts::refreshAll();
    }
}
