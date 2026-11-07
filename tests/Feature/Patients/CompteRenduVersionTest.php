<?php

namespace Tests\Feature\Patients;

use App\Models\Clinique;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Contrôle de version du compte rendu (choix de Jim, 06.11.2026) : à
 * 10 h 05 l'assistante enregistre « température 39,2 » ; à 10 h 10 la
 * vétérinaire enregistre le texte ouvert à 10 h 00. Le serveur refuse au
 * lieu d'écraser, et dit qui l'a modifié et quand, avec son texte.
 */
class CompteRenduVersionTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private RendezVous $rendezVous;

    private string $jetonVeto;

    private string $jetonAssistante;

    private User $assistante;

    private string $uri;

    protected function setUp(): void
    {
        parent::setUp();

        $clinique = Clinique::factory()->create();
        $this->rendezVous = RendezVous::factory()->create([
            'clinique_id' => $clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $clinique->id])->id,
        ]);
        ['jeton' => $this->jetonVeto] = $this->employeAvecJeton($clinique);
        ['user' => $this->assistante, 'jeton' => $this->jetonAssistante] = $this->employeAvecJeton($clinique);
        $this->uri = "/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu";
    }

    public function test_le_compte_rendu_sert_sa_version(): void
    {
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($this->jetonVeto))
            ->assertCreated()
            ->assertJsonPath('data.version', 1);

        $this->putJson($this->uri, ['s' => 'Boiterie depuis 3 jours.', 'version' => 1], $this->enTetes($this->jetonVeto))
            ->assertOk()
            ->assertJsonPath('data.version', 2);
    }

    public function test_le_serveur_refuse_si_quelqu_un_a_enregistre_depuis_l_ouverture(): void
    {
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($this->jetonVeto))->assertCreated();

        // 10 h 05 : l'assistante, qui a ouvert la version 1
        $this->travel(5)->minutes();
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'o' => 'Température 39,2.', 'version' => 1], $this->enTetes($this->jetonAssistante))->assertOk();

        // 10 h 10 : la vétérinaire, qui a ouvert la version 1 aussi
        $this->travel(5)->minutes();
        $this->putJson($this->uri, ['s' => 'Boiterie, appui refusé.', 'o' => '', 'version' => 1], $this->enTetes($this->jetonVeto))
            ->assertStatus(409)
            ->assertJsonPath('data.o', 'Température 39,2.')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.modifie_par', $this->assistante->name)
            ->assertJsonStructure(['message', 'data' => ['texte_modifie_le']]);

        // Rien n'est écrasé
        $this->getJson($this->uri, $this->enTetes($this->jetonVeto))
            ->assertJsonPath('data.o', 'Température 39,2.')
            ->assertJsonPath('data.s', 'Boiterie.');
    }

    public function test_deux_premiers_textes_en_meme_temps_ne_s_ecrasent_pas(): void
    {
        $this->putJson($this->uri, ['s' => 'Premier.', 'version' => 0], $this->enTetes($this->jetonAssistante))->assertCreated();

        $this->putJson($this->uri, ['s' => 'Second.', 'version' => 0], $this->enTetes($this->jetonVeto))
            ->assertStatus(409)
            ->assertJsonPath('data.s', 'Premier.');
    }

    public function test_la_signature_refuse_aussi_un_texte_depasse(): void
    {
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($this->jetonVeto))->assertCreated();
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'o' => 'Température 39,2.', 'version' => 1], $this->enTetes($this->jetonAssistante))->assertOk();

        $this->postJson("{$this->uri}/signer", ['s' => 'Boiterie.', 'o' => '', 'version' => 1], $this->enTetes($this->jetonVeto))
            ->assertStatus(409);
        $this->assertNull($this->rendezVous->compteRendu()->first()->signe_le);
    }

    public function test_un_texte_identique_au_serveur_passe_meme_avec_une_ancienne_version(): void
    {
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($this->jetonVeto))->assertCreated();
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'o' => 'T 39,2.', 'version' => 1], $this->enTetes($this->jetonAssistante))->assertOk();

        $this->putJson($this->uri, ['s' => 'Boiterie.', 'o' => 'T 39,2.', 'version' => 1], $this->enTetes($this->jetonVeto))
            ->assertOk()
            ->assertJsonPath('data.version', 2);
    }

    public function test_une_ligne_notee_par_un_calculateur_ne_change_pas_la_version(): void
    {
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($this->jetonVeto))->assertCreated();
        $this->postJson("{$this->uri}/outils", ['texte' => 'Fluidothérapie : 120 ml/h'], $this->enTetes($this->jetonAssistante))->assertOk();

        $this->putJson($this->uri, ['s' => 'Boiterie depuis 3 jours.', 'version' => 1], $this->enTetes($this->jetonVeto))->assertOk();
    }

    /**
     * Quatrième vérification du 06.11.2026 : deux premiers enregistrements en
     * même temps. L'autre requête crée la ligne pile entre la lecture et
     * l'écriture de celle-ci (simulé ici) : un refus 409, jamais une erreur 500.
     */
    public function test_deux_premiers_enregistrements_simultanes_donnent_un_refus_et_pas_une_erreur(): void
    {
        $rendezVous = $this->rendezVous;
        $fait = false;
        // L'autre requête écrit juste après la première lecture de celle-ci
        DB::listen(function ($requete) use ($rendezVous, &$fait): void {
            if ($fait || ! str_contains($requete->sql, 'from "comptes_rendus"')) {
                return;
            }
            $fait = true;
            DB::table('comptes_rendus')->insert([
                'rendez_vous_id' => $rendezVous->id, 's' => 'Premier.', 'version' => 1,
                'texte_modifie_le' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->putJson($this->uri, ['s' => 'Second.', 'version' => 0], $this->enTetes($this->jetonVeto))
            ->assertStatus(409)
            ->assertJsonPath('data.s', 'Premier.');
    }

    /** Signé entre-temps (07.11.2026) : le refus porte le compte rendu signé, pas une erreur de formulaire */
    public function test_signe_entre_temps_le_refus_porte_le_compte_rendu_signe(): void
    {
        $this->putJson($this->uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($this->jetonVeto))->assertCreated();
        $this->postJson("{$this->uri}/signer", ['s' => 'Boiterie.', 'version' => 1], $this->enTetes($this->jetonVeto))->assertOk();

        $this->putJson($this->uri, ['s' => 'Boiterie, appui refusé.', 'version' => 1], $this->enTetes($this->jetonAssistante))
            ->assertStatus(409)
            ->assertJsonPath('data.s', 'Boiterie.')
            ->assertJsonStructure(['data' => ['signe_le', 'signe_par']]);
    }

    /**
     * Deuxième vérification du 07.11.2026 : sous MySQL, deux premiers
     * enregistrements se bloquaient l'un l'autre (verrous d'intervalle).
     * Le rendez-vous est verrouillé d'abord, avant toute lecture du compte
     * rendu, à l'enregistrement comme pour une ligne notée.
     */
    public function test_le_rendez_vous_est_verrouille_avant_le_compte_rendu(): void
    {
        // Sonde : sous SQLite, un verrou ne s'écrit pas ; on le marque d'un commentaire pour le voir passer
        $connexion = DB::connection();
        $connexion->setQueryGrammar(new class($connexion) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value === true ? ' /* verrou */' : '';
            }
        });
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $this->putJson($this->uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($this->jetonVeto))->assertCreated();
        $this->postJson("{$this->uri}/outils", ['texte' => 'Fluidothérapie : 120 ml/h'], $this->enTetes($this->jetonVeto))->assertOk();

        // Dans chaque requête, le rendez-vous trouvé est aussitôt verrouillé, avant toute lecture du compte rendu
        $trouves = array_keys(array_filter($requetes, fn (string $sql): bool => str_starts_with($sql, 'select * from "rendez_vous" where exists')));
        $this->assertCount(2, $trouves);
        foreach ($trouves as $i) {
            $this->assertStringContainsString('from "rendez_vous"', $requetes[$i + 1]);
            $this->assertStringContainsString('/* verrou */', $requetes[$i + 1], 'Le rendez-vous n’est pas verrouillé d’abord : '.$requetes[$i + 1]);
        }
    }
}
