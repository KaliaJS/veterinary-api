<?php

namespace Tests\Feature;

use App\Auth\EmetteurJetons;
use App\Auth\RolesParDefaut;
use App\Enums\VerificationOrganisation;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AnnuaireSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Le jeu de démonstration (DatabaseSeeder) passe d'un bout à l'autre sur
 * la base de test et produit ce que le front attend : l'organisation
 * Deux Rives, ses deux sociétés et cliniques, le compte d'Anna Meyer
 * (administratrice) qui s'y connecte, et l'annuaire. Hermétique : les
 * photos de l'annuaire (Unsplash) viennent d'un faux serveur, les disques
 * sont factices.
 */
class SeederDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_seeder_de_demo_passe_et_produit_les_donnees_attendues(): void
    {
        Storage::fake('local');
        Storage::fake('prive');
        $photo = UploadedFile::fake()->image('photo.jpg', 64, 40)->getContent();
        Http::fake(['images.unsplash.com/*' => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);

        $this->seed(DatabaseSeeder::class);

        // Deux Rives : deux sociétés, deux cliniques, ouverte
        $organisation = Organisation::query()->where('nom', 'Deux Rives Vétérinaires')->sole();
        $this->assertSame(VerificationOrganisation::Verifiee, $organisation->verification);
        $this->assertSame('actif', $organisation->statut_abonnement);
        $this->assertSame(
            ['Deux Rives Vétérinaires SA', 'Vet Lac Léman Sàrl'],
            $organisation->societes()->orderBy('nom')->pluck('nom')->all(),
        );
        $cliniques = Clinique::withoutGlobalScopes()
            ->whereHas('societe', fn ($societe) => $societe->where('organisation_id', $organisation->id))
            ->orderBy('nom')
            ->pluck('nom')
            ->all();
        $this->assertSame(['Deux Rives', 'Le Lac'], $cliniques);

        // Le compte de démonstration : administratrice de Deux Rives
        $anna = User::query()->where('email', 'a.meyer@deuxrives.ch')->sole();
        $fiche = Employe::withoutGlobalScopes()->where('user_id', $anna->id)->sole();
        $this->assertSame($organisation->id, $fiche->organisation_id);
        $this->assertSame(RolesParDefaut::ADMINISTRATEUR, $fiche->role->nom);

        // Chaque employé a son compte, et a donné son accord pour le site public
        $this->assertSame(0, Employe::withoutGlobalScopes()->whereNull('user_id')->count());
        $this->assertSame(0, Employe::withoutGlobalScopes()->whereNull('accord_site_le')->count());

        // Des patients, un planning, et l'annuaire : 36 cliniques plus les deux de Deux Rives, toutes photographiées
        // (18 photos téléchargées une fois, puis reprises du cache)
        $this->assertGreaterThan(0, Patient::withoutGlobalScopes()->where('organisation_id', $organisation->id)->count());
        $this->assertGreaterThan(0, RendezVous::withoutGlobalScopes()->count());
        $this->assertSame(38, Clinique::withoutGlobalScopes()->count());
        $this->assertSame(0, Clinique::withoutGlobalScopes()->whereNull('photo_chemin')->count());
        Http::assertSentCount(18);

        // L'annuaire se rejoue sans rien créer deux fois ni retélécharger
        $this->seed(AnnuaireSeeder::class);
        $this->assertSame(38, Clinique::withoutGlobalScopes()->count());
        Http::assertSentCount(18);

        // Anna se connecte à Deux Rives et retrouve son planning
        $deuxRives = Clinique::withoutGlobalScopes()->where('nom', 'Deux Rives')->sole();
        $jeton = app(EmetteurJetons::class)->emettre($anna, $deuxRives->id)['jeton']->plainTextToken;
        $entetes = ['Authorization' => 'Bearer '.$jeton];

        $this->getJson('/api/v1/auth/moi', $entetes)
            ->assertOk()
            ->assertJsonPath('data.clinique.nom', 'Deux Rives')
            ->assertJsonPath('data.employe.id', $fiche->id);
        $this->assertNotEmpty($this->getJson('/api/v1/patients', $entetes)->assertOk()->json('data'));
        $jour = CarbonImmutable::now('Europe/Zurich')->startOfDay();
        $planning = $this->getJson('/api/v1/rendez-vous?'.http_build_query([
            'du' => $jour->utc()->toIso8601String(),
            'au' => $jour->addDay()->utc()->toIso8601String(),
        ]), $entetes)->assertOk()->json('data');
        $this->assertNotEmpty($planning);
    }
}
