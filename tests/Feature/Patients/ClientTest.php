<?php

namespace Tests\Feature\Patients;

use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Propriétaires : fiche, agrégats dérivés (solde dû = somme des dettes
 * des animaux, no-shows dérivés des RDV, client depuis = min des
 * fiches) et compte espace client (0..1). Jamais de suppression.
 */
class ClientTest extends TestCase
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
            'email' => 'martin.famille@bluewin.ch',
        ]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_la_liste_porte_les_agregats_derives_du_foyer(): void
    {
        Patient::factory()->for($this->client)->create(['dette' => 120, 'client_depuis' => 2019]);
        Patient::factory()->for($this->client)->create(['dette' => 30.5, 'client_depuis' => 2022]);

        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        RendezVous::factory()->count(2)->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $ligne->id,
            'client_id' => $this->client->id,
            'status' => StatutRendezVous::Noshow,
        ]);

        $this->getJson('/api/v1/clients', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->client->id)
            ->assertJsonPath('data.0.nombre_patients', 2)
            ->assertJsonPath('data.0.solde_du', 150.5)
            ->assertJsonPath('data.0.no_shows', 2)
            ->assertJsonPath('data.0.client_depuis', 2019)
            ->assertJsonPath('data.0.a_un_compte', false);
    }

    public function test_les_no_shows_comptent_toutes_les_cliniques_de_l_organisation(): void
    {
        $cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $cliniqueSoeur->id]);
        RendezVous::factory()->create([
            'clinique_id' => $cliniqueSoeur->id,
            'ligne_planning_id' => $ligne->id,
            'client_id' => $this->client->id,
            'status' => StatutRendezVous::Noshow,
        ]);

        $this->getJson('/api/v1/clients', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.no_shows', 1);
    }

    public function test_la_recherche_trouve_un_client_par_le_nom_de_son_animal(): void
    {
        $autre = Client::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);
        Patient::factory()->for($autre)->create(['nom' => 'Caramel']);

        $reponse = $this->getJson('/api/v1/clients?q=caramel', $this->enTetes($this->jeton))->assertOk();

        $this->assertSame([$autre->id], array_column($reponse->json('data'), 'id'));
    }

    public function test_le_tri_par_nombre_d_animaux_classe_les_grands_foyers_d_abord(): void
    {
        $foyer = Client::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);
        Patient::factory()->count(3)->for($foyer)->create();

        $this->getJson('/api/v1/clients?tri=animaux', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.id', $foyer->id);
    }

    public function test_un_proprietaire_se_cree_sans_civilite(): void
    {
        $reponse = $this->postJson('/api/v1/clients', [
            'prenom' => 'Claire',
            'nom' => 'Perrin',
            'tel' => '079 555 12 34',
            'npa' => '1003',
            'localite' => 'Lausanne',
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.civilite', null)
            ->assertJsonPath('data.nom_affiche', 'Perrin');

        $this->assertDatabaseHas('clients', [
            'id' => $reponse->json('data.id'),
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);
    }

    public function test_la_fiche_se_modifie_et_le_npa_est_valide(): void
    {
        $this->patchJson("/api/v1/clients/{$this->client->id}", [
            'tel' => '078 000 11 22',
            'npa' => '12345',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('npa');

        $this->patchJson("/api/v1/clients/{$this->client->id}", [
            'tel' => '078 000 11 22',
            'localite' => 'Pully',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.tel', '078 000 11 22')
            ->assertJsonPath('data.localite', 'Pully');
    }

    public function test_la_liste_sert_les_noms_des_animaux_du_foyer(): void
    {
        Patient::factory()->for($this->client)->create(['nom' => 'Rex']);
        Patient::factory()->for($this->client)->create(['nom' => 'Mimi']);
        $sansAnimal = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $reponse = $this->getJson('/api/v1/clients', $this->enTetes($this->jeton))->assertOk();

        $parId = collect($reponse->json('data'))->keyBy('id');

        $this->assertSame(['Mimi', 'Rex'], $parId[$this->client->id]['noms_patients']);
        $this->assertSame(2, $parId[$this->client->id]['nombre_patients']);
        $this->assertSame([], $parId[$sansAnimal->id]['noms_patients']);
        $this->assertArrayNotHasKey('patients', $parId[$this->client->id]);

        $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.noms_patients', ['Mimi', 'Rex']);
    }

    public function test_la_fiche_detaillee_liste_les_animaux_du_foyer(): void
    {
        $patients = Patient::factory()->count(2)->for($this->client)->create();

        $reponse = $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))->assertOk();

        $this->assertEqualsCanonicalizing(
            $patients->pluck('id')->all(),
            array_column($reponse->json('data.patients'), 'id'),
        );
    }

    public function test_le_compte_espace_client_s_ouvre_avec_l_email_de_la_fiche(): void
    {
        $this->postJson("/api/v1/clients/{$this->client->id}/compte", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.a_un_compte', true);

        $utilisateur = User::where('email', 'martin.famille@bluewin.ch')->sole();
        $this->assertSame($utilisateur->id, $this->client->fresh()->user_id);
    }

    public function test_ouvrir_un_second_compte_est_refuse(): void
    {
        $this->postJson("/api/v1/clients/{$this->client->id}/compte", [], $this->enTetes($this->jeton))->assertOk();

        $this->postJson("/api/v1/clients/{$this->client->id}/compte", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client');
    }

    /** Une adresse qui a déjà un compte : la fiche s'y rattache, la clinique ne voit pas la différence (24.10.2026). */
    public function test_une_adresse_qui_a_deja_un_compte_y_rattache_la_fiche_sans_le_dire(): void
    {
        $existant = User::factory()->create(['email' => 'pris@exemple.ch', 'email_verified_at' => now()]);
        $neuve = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $avecCompte = $this->postJson("/api/v1/clients/{$this->client->id}/compte", ['email' => 'Pris@Exemple.ch'], $this->enTetes($this->jeton))
            ->assertOk()->json('data');
        $sansCompte = $this->postJson("/api/v1/clients/{$neuve->id}/compte", ['email' => 'libre@exemple.ch'], $this->enTetes($this->jeton))
            ->assertOk()->json('data');

        $this->assertSame($existant->id, $this->client->fresh()->user_id);
        $this->assertSame(array_keys($avecCompte), array_keys($sansCompte));
        $this->assertTrue($avecCompte['a_un_compte']);
        $this->assertSame(1, User::query()->where('email', 'pris@exemple.ch')->count());

        // Révoquée, la fiche se détache ; le compte, prouvé par son titulaire, reste
        $this->deleteJson("/api/v1/clients/{$this->client->id}/compte", [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertModelExists($existant);
    }

    /** Avec ou sans compte existant, le même travail : un hachage de mot de passe, rien de plus (pas de différence de temps de réponse). */
    public function test_l_ouverture_fait_le_meme_travail_que_le_compte_existe_ou_non(): void
    {
        User::factory()->create(['email' => 'pris@exemple.ch']);
        $neuve = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        // Un hacheur qui compte ses hachages
        $compteur = new class(['rounds' => 4]) extends BcryptHasher
        {
            public int $hachages = 0;

            public function make(#[\SensitiveParameter] $value, array $options = []): string
            {
                $this->hachages++;

                return parent::make($value, $options);
            }
        };
        Hash::extend('compteur', fn () => $compteur);
        config(['hashing.driver' => 'compteur']);
        Hash::forgetDrivers();

        $this->postJson("/api/v1/clients/{$this->client->id}/compte", ['email' => 'pris@exemple.ch'], $this->enTetes($this->jeton))->assertOk();
        $avecCompte = $compteur->hachages;
        $this->postJson("/api/v1/clients/{$neuve->id}/compte", ['email' => 'libre@exemple.ch'], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(1, $avecCompte);
        $this->assertSame(2, $compteur->hachages);
    }

    public function test_une_fiche_sans_email_est_refusee(): void
    {
        $sansEmail = Client::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'email' => null,
        ]);

        $this->postJson("/api/v1/clients/{$sansEmail->id}/compte", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_revoquer_le_compte_detache_et_supprime_l_utilisateur_portail(): void
    {
        $this->postJson("/api/v1/clients/{$this->client->id}/compte", [], $this->enTetes($this->jeton))->assertOk();
        $utilisateurId = $this->client->fresh()->user_id;

        $this->deleteJson("/api/v1/clients/{$this->client->id}/compte", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertNull($this->client->fresh()->user_id);
        $this->assertDatabaseMissing('users', ['id' => $utilisateurId]);
    }

    public function test_revoquer_ne_supprime_jamais_un_compte_employe(): void
    {
        ['user' => $utilisateurEmploye] = $this->employeAvecJeton($this->clinique);
        Client::withoutGlobalScopes()->whereKey($this->client->id)->update(['user_id' => $utilisateurEmploye->id]);

        $this->deleteJson("/api/v1/clients/{$this->client->id}/compte", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertNull($this->client->fresh()->user_id);
        $this->assertDatabaseHas('users', ['id' => $utilisateurEmploye->id]);
    }

    public function test_aucune_route_de_suppression_de_client(): void
    {
        $this->deleteJson("/api/v1/clients/{$this->client->id}", [], $this->enTetes($this->jeton))
            ->assertStatus(405);
    }
}
