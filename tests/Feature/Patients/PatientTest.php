<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\RendezVous;
use App\Models\Vaccination;
use App\Models\Visite;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Fiche patient : alertes dérivées (agressif, allergies, solde),
 * filtres et tris de l'écran Patients, dossier complet au show.
 * Jamais de suppression de fiche.
 */
class PatientTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Client $client;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->client = Client::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_les_alertes_sont_derivees_de_la_fiche(): void
    {
        Patient::factory()->for($this->client)->create([
            'nom' => 'Loki',
            'est_agressif' => true,
            'a_allergies' => false,
            'dette' => 85,
        ]);

        $this->getJson('/api/v1/patients', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.alertes', ['agressif', 'solde'])
            ->assertJsonPath('data.0.client.id', $this->client->id);
    }

    public function test_les_filtres_de_l_ecran_patients(): void
    {
        $chien = Patient::factory()->for($this->client)->create(['espece' => 'CH', 'nom' => 'Rex', 'est_agressif' => false, 'a_allergies' => false, 'dette' => 0]);
        $chat = Patient::factory()->for($this->client)->create(['espece' => 'CT', 'nom' => 'Mimi', 'est_agressif' => false, 'a_allergies' => false, 'dette' => 0]);
        $lapin = Patient::factory()->for($this->client)->create(['espece' => 'LP', 'nom' => 'Pixel', 'est_agressif' => false, 'a_allergies' => true, 'dette' => 0]);

        Vaccination::factory()->for($chat)->create(['echeance_le' => aujourdhui()->addDays(10)]);
        Vaccination::factory()->for($chien)->create(['echeance_le' => aujourdhui()->addDays(300)]);

        $attendus = [
            'chien' => [$chien->id],
            'chat' => [$chat->id],
            'nac' => [$lapin->id],
            'alertes' => [$lapin->id],
            'rappels' => [$chat->id],
        ];

        foreach ($attendus as $filtre => $ids) {
            $reponse = $this->getJson("/api/v1/patients?filtre={$filtre}", $this->enTetes($this->jeton))->assertOk();

            $this->assertSame($ids, array_column($reponse->json('data'), 'id'), "Filtre {$filtre}");
        }
    }

    public function test_la_liste_se_trie_par_colonne_dans_les_deux_sens(): void
    {
        $autreClient = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Aubert']);
        $this->client->update(['nom' => 'Zbinden']);
        // Alertes posées explicitement (la fabrique tire les allergies au hasard)
        $rex = Patient::factory()->for($this->client)->create(['nom' => 'Rex', 'est_agressif' => true, 'a_allergies' => false, 'dette' => 0]);
        $mimi = Patient::factory()->for($autreClient)->create(['nom' => 'Mimi', 'est_agressif' => false, 'a_allergies' => false, 'dette' => 0]);
        $pixel = Patient::factory()->for($this->client)->create(['nom' => 'Pixel', 'est_agressif' => false, 'a_allergies' => false, 'dette' => 0]);
        $rex->pesees()->create(['poids_kg' => 24.5, 'pesee_le' => aujourdhui()->subDays(3)]);
        $rex->pesees()->create(['poids_kg' => 26, 'pesee_le' => aujourdhui()]);
        $mimi->pesees()->create(['poids_kg' => 4.2, 'pesee_le' => aujourdhui()]);
        Visite::factory()->for($mimi)->create(['visite_le' => aujourdhui()->subDays(2)]);
        Visite::factory()->for($pixel)->create(['visite_le' => aujourdhui()->subDays(40)]);

        $attendus = [
            'tri=nom' => [$mimi->id, $pixel->id, $rex->id],
            'tri=nom&sens=desc' => [$rex->id, $pixel->id, $mimi->id],
            'tri=proprietaire' => [$mimi->id, $pixel->id, $rex->id],
            'tri=alertes' => [$rex->id, $mimi->id, $pixel->id],
            'tri=poids' => [$rex->id, $mimi->id, $pixel->id],
            'tri=poids&sens=asc' => [$mimi->id, $rex->id, $pixel->id],
            'tri=visite' => [$mimi->id, $pixel->id, $rex->id],
            'tri=visite&sens=asc' => [$pixel->id, $mimi->id, $rex->id],
        ];

        foreach ($attendus as $query => $ids) {
            $reponse = $this->getJson("/api/v1/patients?{$query}", $this->enTetes($this->jeton))->assertOk();

            $this->assertSame($ids, array_column($reponse->json('data'), 'id'), "Tri {$query}");
        }
    }

    public function test_la_photo_de_l_animal_se_televerse_se_sert_et_se_supprime(): void
    {
        Storage::fake('prive');
        $rex = Patient::factory()->for($this->client)->create(['nom' => 'Rex']);

        $url = $this->post("/api/v1/patients/{$rex->id}/photo", ['photo' => UploadedFile::fake()->image('rex.jpg', 300, 300)], $this->enTetes($this->jeton))
            ->assertOk()
            ->json('data.photo_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('/photos-animaux/', $url);
        Storage::disk('prive')->assertExists($rex->fresh()->photo_chemin);
        // Réécrite en WebP par GD : rien du fichier déposé (EXIF, appareil, lieu) ne reste
        $this->assertStringEndsWith('.webp', $rex->fresh()->photo_chemin);
        $this->assertSame('RIFF', substr(Storage::disk('prive')->get($rex->fresh()->photo_chemin), 0, 4));
        $this->assertStringNotContainsString('Exif', Storage::disk('prive')->get($rex->fresh()->photo_chemin));

        // Gardée par le navigateur seulement : jamais par un cache partagé (bord de Cloudflare), une photo retirée n'y survit pas (27.10.2026)
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
        $this->post("/api/v1/patients/{$rex->id}/photo", ['photo' => UploadedFile::fake()->create('rex.svg', 10, 'image/svg+xml')], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo' => 'La photo doit être une image PNG, JPG ou WebP.']);

        $this->deleteJson("/api/v1/patients/{$rex->id}/photo", [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.photo_url', null);
        $this->assertNull($rex->fresh()->photo_chemin);
    }

    public function test_la_liste_sert_le_dernier_poids_et_la_courbe_des_douze_dernieres_pesees(): void
    {
        $patient = Patient::factory()->for($this->client)->create(['nom' => 'Rex']);
        $sansPesee = Patient::factory()->for($this->client)->create(['nom' => 'Zéro']);

        // Quatorze pesées : la courbe n'en garde que douze, dans l'ordre
        // chronologique ; le dernier poids est celui de la date la plus
        // récente, pas de l'id le plus grand.
        foreach (range(1, 14) as $i) {
            Pesee::factory()->for($patient)->create(['poids_kg' => 10 + $i, 'pesee_le' => aujourdhui()->subDays(30 - $i)]);
        }
        Pesee::factory()->for($patient)->create(['poids_kg' => 5, 'pesee_le' => aujourdhui()->subDays(100)]);

        $reponse = $this->getJson('/api/v1/patients', $this->enTetes($this->jeton))->assertOk();

        $parId = collect($reponse->json('data'))->keyBy('id');

        $this->assertEquals(24.0, $parId[$patient->id]['dernier_poids_kg']);
        $this->assertEquals(range(13, 24), $parId[$patient->id]['courbe_poids']);
        $this->assertNull($parId[$sansPesee->id]['dernier_poids_kg']);
        $this->assertSame([], $parId[$sansPesee->id]['courbe_poids']);

        $this->getJson("/api/v1/patients/{$patient->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.dernier_poids_kg', 24)
            ->assertJsonCount(15, 'data.pesees');
    }

    public function test_la_liste_porte_les_facettes_des_filtres_sur_la_recherche_courante(): void
    {
        // La recherche couvre aussi race, puce et propriétaire : neutralisés.
        $this->client->update(['nom' => 'Dupont']);
        $neutre = ['race' => null, 'puce' => null, 'est_agressif' => false, 'a_allergies' => false, 'dette' => 0];

        $chien = Patient::factory()->for($this->client)->create([...$neutre, 'espece' => 'CH', 'nom' => 'Rex', 'est_agressif' => true]);
        Patient::factory()->for($this->client)->create([...$neutre, 'espece' => 'CH', 'nom' => 'Rocky', 'dette' => 12]);
        $chat = Patient::factory()->for($this->client)->create([...$neutre, 'espece' => 'CT', 'nom' => 'Mimi']);
        Patient::factory()->for($this->client)->create([...$neutre, 'espece' => 'LP', 'nom' => 'Pixel', 'a_allergies' => true]);

        Vaccination::factory()->for($chat)->create(['echeance_le' => aujourdhui()->addDays(10)]);
        Vaccination::factory()->for($chat)->create(['echeance_le' => aujourdhui()->addDays(20)]);
        Vaccination::factory()->for($chien)->create(['echeance_le' => aujourdhui()->addDays(300)]);

        // Les facettes ignorent le filtre actif : chaque onglet affiche son compteur.
        $this->getJson('/api/v1/patients?filtre=chat', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.facettes', [
                'tous' => 4, 'chien' => 2, 'chat' => 1, 'nac' => 1, 'alertes' => 3, 'rappels' => 1,
            ]);

        // … mais suivent la recherche.
        $this->getJson('/api/v1/patients?q=r', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('meta.facettes.tous', 2)
            ->assertJsonPath('meta.facettes.chien', 2)
            ->assertJsonPath('meta.facettes.chat', 0)
            ->assertJsonPath('meta.facettes.alertes', 2)
            ->assertJsonPath('meta.facettes.rappels', 0);
    }

    public function test_le_prochain_rendez_vous_sort_en_liste_et_en_fiche_toutes_cliniques_confondues(): void
    {
        $patient = Patient::factory()->for($this->client)->create(['nom' => 'Rex']);
        $sansRendezVous = Patient::factory()->for($this->client)->create(['nom' => 'Zéro']);
        $cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        // Passé, annulé, no-show : ignorés. Le plus proche à venir gagne,
        // même dans une clinique sœur.
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(30)]);
        RendezVous::factory()->pourPatient($patient)->annule()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30)]);
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id, 'status' => 'noshow', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30)]);
        $prochain = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $cliniqueSoeur->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(30), 'reason' => 'Contrôle']);
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addMinutes(30)]);

        $reponse = $this->getJson('/api/v1/patients', $this->enTetes($this->jeton))->assertOk();

        $parId = collect($reponse->json('data'))->keyBy('id');

        $this->assertSame($prochain->id, $parId[$patient->id]['prochain_rendez_vous']['id']);
        $this->assertSame($cliniqueSoeur->id, $parId[$patient->id]['prochain_rendez_vous']['clinique_id']);
        $this->assertSame('Contrôle', $parId[$patient->id]['prochain_rendez_vous']['reason']);
        $this->assertSame(
            ['id', 'clinique_id', 'ligne_planning_id', 'type', 'status', 'reason', 'starts_at', 'ends_at'],
            array_keys($parId[$patient->id]['prochain_rendez_vous']),
        );
        $this->assertNull($parId[$sansRendezVous->id]['prochain_rendez_vous']);

        $this->getJson("/api/v1/patients/{$patient->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.prochain_rendez_vous.id', $prochain->id);
    }

    public function test_le_tri_par_rappels_met_la_plus_proche_echeance_d_abord(): void
    {
        $sansVaccin = Patient::factory()->for($this->client)->create(['nom' => 'Aramis']);
        $urgent = Patient::factory()->for($this->client)->create(['nom' => 'Zoé']);
        $tranquille = Patient::factory()->for($this->client)->create(['nom' => 'Bianca']);

        Vaccination::factory()->for($urgent)->create(['echeance_le' => aujourdhui()->addDays(5)]);
        Vaccination::factory()->for($tranquille)->create(['echeance_le' => aujourdhui()->addDays(200)]);

        $reponse = $this->getJson('/api/v1/patients?tri=rappels', $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(
            [$urgent->id, $tranquille->id, $sansVaccin->id],
            array_column($reponse->json('data'), 'id'),
        );
    }

    public function test_la_recherche_couvre_nom_puce_et_proprietaire(): void
    {
        $patient = Patient::factory()->for($this->client)->create([
            'nom' => 'Gribouille',
            'puce' => '756 091 234 567 890',
        ]);
        Patient::factory()->for(Client::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'nom' => 'Delacroix',
        ]))->create(['nom' => 'Ombre']);

        foreach (['gribouille', '756 091', $this->client->nom] as $q) {
            $reponse = $this->getJson('/api/v1/patients?q='.urlencode($q), $this->enTetes($this->jeton))->assertOk();

            $this->assertContains($patient->id, array_column($reponse->json('data'), 'id'), "Recherche {$q}");
        }
    }

    public function test_une_fiche_se_cree_avec_les_defauts_du_metier(): void
    {
        $reponse = $this->postJson('/api/v1/patients', [
            'client_id' => $this->client->id,
            'nom' => 'Noisette',
            'espece' => 'CH',
            'sexe' => 'Femelle',
            'puce' => '756091234567890',
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.nom', 'Noisette')
            ->assertJsonPath('data.puce', '756 091 234 567 890')
            ->assertJsonPath('data.client_depuis', aujourdhui()->year)
            ->assertJsonPath('data.dette', fn (mixed $dette): bool => (float) $dette === 0.0)
            ->assertJsonPath('data.alertes', []);

        $this->assertDatabaseHas('patients', [
            'id' => $reponse->json('data.id'),
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);
    }

    public function test_le_proprietaire_est_requis_a_la_creation(): void
    {
        $this->postJson('/api/v1/patients', [
            'nom' => 'Perdu',
            'espece' => 'CH',
            'sexe' => 'Mâle',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');
    }

    public function test_le_dossier_complet_sort_au_show(): void
    {
        $patient = Patient::factory()->for($this->client)->create();
        Pesee::factory()->for($patient)->create(['pesee_le' => aujourdhui()->subMonths(3), 'poids_kg' => 11.2]);
        Pesee::factory()->for($patient)->create(['pesee_le' => aujourdhui(), 'poids_kg' => 11.8]);
        Vaccination::factory()->for($patient)->create();
        Visite::factory()->for($patient)->create();

        $reponse = $this->getJson("/api/v1/patients/{$patient->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data.pesees')
            ->assertJsonCount(1, 'data.vaccinations')
            ->assertJsonCount(1, 'data.visites')
            ->assertJsonPath('data.dents', [])
            ->assertJsonPath('data.releves_ophtalmologiques', []);

        // Pesées en ordre chronologique — la courbe de poids du front.
        $this->assertSame([11.2, 11.8], array_column($reponse->json('data.pesees'), 'poids_kg'));
    }

    public function test_la_fiche_se_modifie_sans_toucher_a_la_dette(): void
    {
        $patient = Patient::factory()->for($this->client)->create(['dette' => 40]);

        $this->patchJson("/api/v1/patients/{$patient->id}", [
            'nom' => 'Rebaptisé',
            'est_sterilise' => true,
            'dette' => 0,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.nom', 'Rebaptisé')
            ->assertJsonPath('data.est_sterilise', true)
            ->assertJsonPath('data.dette', fn (mixed $dette): bool => (float) $dette === 40.0);
    }

    /** Juste après minuit à l'heure de la clinique, la date du jour n'est pas « dans le futur » (24.10.2026) : ni en UTC, ni à Zurich. */
    public function test_le_deces_du_jour_s_enregistre_juste_apres_minuit(): void
    {
        // Une clinique loin de Zurich : il y est déjà le 15 quand Zurich est encore le 14
        $this->clinique->update(['fuseau_horaire' => 'Pacific/Kiritimati']);
        $this->travelTo(CarbonImmutable::parse('2026-11-15 00:30', 'Pacific/Kiritimati'));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $patient = Patient::factory()->for($this->client)->create();

        $this->patchJson("/api/v1/patients/{$patient->id}", ['decede_le' => '2026-11-15'], $this->enTetes($jeton))->assertOk();
        $this->assertSame('2026-11-15', $patient->refresh()->decede_le->toDateString());
        $this->patchJson("/api/v1/patients/{$patient->id}", ['decede_le' => '2026-11-16'], $this->enTetes($jeton))->assertUnprocessable();
    }

    public function test_aucune_route_de_suppression_de_patient(): void
    {
        $patient = Patient::factory()->for($this->client)->create();

        $this->deleteJson("/api/v1/patients/{$patient->id}", [], $this->enTetes($this->jeton))
            ->assertStatus(405);
    }
}
