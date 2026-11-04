<?php

namespace Tests\Feature\Patients;

use App\Auth\ContexteClinique;
use App\Enums\Espece;
use App\Events\DentalChartChanged;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DentalOverview;
use App\Models\Patient;
use App\Models\ReleveDentaire;
use App\Support\DentalFormula;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Schéma dentaire modernisé (demande de Jim, 04.11.2026) : une dent porte
 * plusieurs états et une note libre ; le relevé garde aussi le bilan de la
 * bouche (gingivite, tartre, occlusion, bloc local). Les anciens relevés
 * (un état par dent) restent lisibles sans perte. Numéros de dent valides
 * pour l'espèce, états connus, longueurs bornées ; une autre organisation
 * ne voit ni ne change rien.
 */
class DentalChartTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $chien;

    private Patient $chat;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->chien = Patient::factory()->for($client)->create(['espece' => Espece::Chien]);
        $this->chat = Patient::factory()->for($client)->create(['espece' => Espece::Chat]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function uri(Patient $patient): string
    {
        return "/api/v1/patients/{$patient->id}/dents";
    }

    /** @return array<string, string|null> */
    private function bilanVide(): array
    {
        return ['gingivitis' => null, 'calculus' => null, 'occlusion' => null, 'local_block' => null];
    }

    public function test_une_dent_peut_porter_plusieurs_etats_a_la_fois(): void
    {
        $this->putJson($this->uri($this->chien), [
            'teeth' => ['108' => ['states' => ['tartre', 'gingivite', 'parodontite']], '208' => ['states' => ['tartre', 'gingivite']], '209' => ['states' => ['tartre']]],
            'overview' => $this->bilanVide(),
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.teeth.108.states', ['tartre', 'gingivite', 'parodontite'])
            // Le même état sur plusieurs dents
            ->assertJsonPath('data.teeth.208.states', ['tartre', 'gingivite'])
            ->assertJsonPath('data.teeth.209.states', ['tartre']);
    }

    public function test_mobilite_et_resorption_sont_des_etats_connus(): void
    {
        $this->putJson($this->uri($this->chat), [
            'teeth' => ['307' => ['states' => ['resorption']], '409' => ['states' => ['mobilite', 'tumeur', 'extraction']], '104' => ['states' => ['absente']], '208' => ['states' => ['fracture']]],
            'overview' => $this->bilanVide(),
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.teeth.307.states', ['resorption'])
            ->assertJsonPath('data.teeth.409.states', ['mobilite', 'tumeur', 'extraction']);
    }

    public function test_chaque_dent_peut_recevoir_une_note_libre(): void
    {
        $this->putJson($this->uri($this->chien), [
            'teeth' => [
                '204' => ['states' => [], 'note' => 'Fêlure à surveiller'],
                '108' => ['states' => ['fracture'], 'note' => 'Pulpe exposée'],
            ],
            'overview' => $this->bilanVide(),
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.teeth.204.note', 'Fêlure à surveiller')
            ->assertJsonPath('data.teeth.204.states', [])
            ->assertJsonPath('data.teeth.108.note', 'Pulpe exposée');
    }

    public function test_le_bilan_gingivite_aucune_legere_moderee_severe(): void
    {
        foreach (['none', 'mild', 'moderate', 'severe'] as $valeur) {
            $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'gingivitis' => $valeur]], $this->enTetes($this->jeton))
                ->assertOk()
                ->assertJsonPath('data.overview.gingivitis', $valeur);
        }
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'gingivitis' => 'heavy']], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('overview.gingivitis');
    }

    public function test_le_bilan_tartre_aucun_minimal_leger_modere_massif(): void
    {
        foreach (['none', 'minimal', 'mild', 'moderate', 'heavy'] as $valeur) {
            $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'calculus' => $valeur]], $this->enTetes($this->jeton))
                ->assertOk()
                ->assertJsonPath('data.overview.calculus', $valeur);
        }
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'calculus' => 'severe']], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('overview.calculus');
    }

    public function test_le_bilan_occlusion_normale_puis_mal1_a_mal4(): void
    {
        foreach (['normal', 'MAL1', 'MAL2', 'MAL3', 'MAL4'] as $valeur) {
            $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'occlusion' => $valeur]], $this->enTetes($this->jeton))
                ->assertOk()
                ->assertJsonPath('data.overview.occlusion', $valeur);
        }
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'occlusion' => 'MAL5']], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('overview.occlusion');
    }

    public function test_le_bilan_bloc_local_en_texte_borne(): void
    {
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'local_block' => 'Bupivacaïne 0,5 %, infraorbitaire']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.overview.local_block', 'Bupivacaïne 0,5 %, infraorbitaire');
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'local_block' => str_repeat('a', 201)]], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('overview.local_block');
    }

    public function test_etats_notes_et_bilan_s_enregistrent_dans_le_dossier_et_se_relisent(): void
    {
        $this->putJson($this->uri($this->chien), [
            'teeth' => ['108' => ['states' => ['tartre', 'gingivite'], 'note' => 'Saigne au sondage'], '309' => ['states' => ['extraction']]],
            'overview' => ['gingivitis' => 'mild', 'calculus' => 'moderate', 'occlusion' => 'MAL2', 'local_block' => 'Lidocaïne'],
        ], $this->enTetes($this->jeton))->assertOk();

        // Relu d'un autre appareil : le dossier du patient le garde
        $this->getJson($this->uri($this->chien), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => [
                'teeth' => [
                    '108' => ['states' => ['tartre', 'gingivite'], 'note' => 'Saigne au sondage'],
                    '309' => ['states' => ['extraction'], 'note' => null],
                ],
                'overview' => ['gingivitis' => 'mild', 'calculus' => 'moderate', 'occlusion' => 'MAL2', 'local_block' => 'Lidocaïne'],
            ]]);

        // Le PUT remplace tout : une dent retirée redevient saine, le bilan se vide
        $this->putJson($this->uri($this->chien), ['teeth' => ['309' => ['states' => ['extraction']]], 'overview' => $this->bilanVide()], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonMissingPath('data.teeth.108')
            ->assertJsonPath('data.overview.gingivitis', null);
        $this->assertSame([309], $this->chien->relevesDentaires()->pluck('dent')->all());

        // La fiche détaillée du patient porte aussi les dents
        $this->getJson("/api/v1/patients/{$this->chien->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.dents.309.states', ['extraction']);
    }

    public function test_un_dossier_sans_releve_rend_un_bilan_vide(): void
    {
        $this->getJson($this->uri($this->chien), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['teeth' => [], 'overview' => $this->bilanVide()]]);
    }

    /** Les relevés enregistrés avant le 04.11.2026 (un état par dent), convertis par la migration sans perte */
    public function test_les_anciens_releves_un_etat_par_dent_restent_lisibles_sans_perte(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_04_000400_dental_chart_multiple_states.php');
        $migration->down();

        $anciens = ['104' => 'fracture', '108' => 'tartre', '204' => 'gingivite', '309' => 'extraction', '409' => 'parodontite', '411' => 'absente', '207' => 'tumeur'];
        foreach ($anciens as $dent => $etat) {
            DB::table('releves_dentaires')->insert(['patient_id' => $this->chien->id, 'dent' => (int) $dent, 'etat' => $etat, 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration->up();

        $reponse = $this->getJson($this->uri($this->chien), $this->enTetes($this->jeton))->assertOk();
        $this->assertCount(count($anciens), $reponse->json('data.teeth'));
        foreach ($anciens as $dent => $etat) {
            $reponse->assertJsonPath("data.teeth.{$dent}.states", [$etat])->assertJsonPath("data.teeth.{$dent}.note", null);
        }
        $this->assertSame(['tartre'], ReleveDentaire::query()->where('dent', 108)->sole()->etats);
    }

    /**
     * Le retour arrière de la migration (vérification du 04.11.2026) : plus
     * de 1000 lignes, chacune repasse à un état, le plus grave ; une dent
     * sans état connu de l'ancienne liste redevient saine ; `etat` NOT NULL.
     */
    public function test_le_retour_arriere_de_la_migration_traite_toutes_les_lignes_et_garde_l_etat_le_plus_grave(): void
    {
        $migration = require database_path('migrations/2026_11_04_000400_dental_chart_multiple_states.php');
        $client = $this->chien->client;
        $patients = Patient::factory()->count(36)->for($client)->create(['espece' => Espece::Chien]);
        $teeth = DentalFormula::teethFor(Espece::Chien);
        $rows = [];
        foreach ($patients as $p => $patient) {
            foreach ($teeth as $t => $dent) {
                // Une dent sur trois n'a que la mobilité (inconnue de l'ancienne liste) : elle s'efface
                $etats = match (($p * 42 + $t) % 3) {
                    0 => ['mobilite'],
                    1 => ['tartre', 'fracture', 'gingivite'],
                    default => ['absente', 'tumeur'],
                };
                $rows[] = ['patient_id' => $patient->id, 'dent' => $dent, 'etats' => json_encode($etats), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('releves_dentaires')->insert($chunk);
        }
        $this->assertGreaterThan(1000, count($rows));
        $attendues = count(array_filter($rows, fn (array $r) => $r['etats'] !== json_encode(['mobilite'])));

        $migration->down();

        $this->assertSame($attendues, DB::table('releves_dentaires')->count());
        $this->assertSame(0, DB::table('releves_dentaires')->whereNull('etat')->count());
        $this->assertSame(
            ['fracture', 'tumeur'],
            DB::table('releves_dentaires')->distinct()->orderBy('etat')->pluck('etat')->all(),
        );
        // NOT NULL comme à l'origine
        $this->expectException(QueryException::class);
        try {
            DB::table('releves_dentaires')->insert(['patient_id' => $this->chien->id, 'dent' => 101, 'etat' => null, 'created_at' => now(), 'updated_at' => now()]);
        } finally {
            $migration->up();
        }
    }

    public function test_apres_la_migration_les_etats_ne_sont_jamais_nuls(): void
    {
        $this->expectException(QueryException::class);
        DB::table('releves_dentaires')->insert(['patient_id' => $this->chien->id, 'dent' => 101, 'etats' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_un_numero_de_dent_invalide_pour_l_espece_est_refuse(): void
    {
        // Le chat n'a pas de 105 (sa première prémolaire du haut est la 106)
        $this->putJson($this->uri($this->chat), ['teeth' => ['105' => ['states' => ['tartre']]], 'overview' => $this->bilanVide()], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teeth');
        // Le chien, si
        $this->putJson($this->uri($this->chien), ['teeth' => ['105' => ['states' => ['tartre']]], 'overview' => $this->bilanVide()], $this->enTetes($this->jeton))
            ->assertOk();
        $this->putJson($this->uri($this->chien), ['teeth' => ['999' => ['states' => ['tartre']]], 'overview' => $this->bilanVide()], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teeth');
        // Une espèce sans schéma dentaire n'en reçoit pas
        $oiseau = Patient::factory()->for($this->chien->client)->create(['espece' => Espece::Oiseau]);
        $this->putJson($this->uri($oiseau), ['teeth' => ['101' => ['states' => ['tartre']]], 'overview' => $this->bilanVide()], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teeth');
    }

    public function test_un_etat_inconnu_ou_double_et_une_note_trop_longue_sont_refuses(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->putJson($this->uri($this->chien), ['teeth' => ['104' => ['states' => ['carie']]], 'overview' => $this->bilanVide()], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teeth.104.states.0');
        $this->putJson($this->uri($this->chien), ['teeth' => ['104' => ['states' => ['tartre', 'tartre']]], 'overview' => $this->bilanVide()], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teeth.104.states.1');
        $this->putJson($this->uri($this->chien), ['teeth' => ['104' => ['states' => [], 'note' => str_repeat('a', 501)]], 'overview' => $this->bilanVide()], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teeth.104.note');
        $this->putJson($this->uri($this->chien), ['teeth' => ['104' => 'tartre'], 'overview' => $this->bilanVide()], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teeth.104');
        $this->assertSame(0, $this->chien->relevesDentaires()->count());
    }

    public function test_une_autre_organisation_ne_lit_ni_ne_change_le_releve(): void
    {
        $this->putJson($this->uri($this->chien), ['teeth' => ['108' => ['states' => ['tartre']]], 'overview' => [...$this->bilanVide(), 'gingivitis' => 'mild']], $this->enTetes($this->jeton))->assertOk();

        // Hors de toute requête : une clinique d'une autre organisation
        app(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());
        $this->getJson($this->uri($this->chien), $this->enTetes($autre))->assertNotFound();
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => $this->bilanVide()], $this->enTetes($autre))->assertNotFound();

        $this->getJson($this->uri($this->chien), $this->enTetes($this->jeton))
            ->assertJsonPath('data.teeth.108.states', ['tartre'])
            ->assertJsonPath('data.overview.gingivitis', 'mild');
    }

    /* ————— Envoi dent par dent (2e vérification, 04.11.2026) ————— */

    public function test_deux_postes_gardent_chacun_leurs_dents(): void
    {
        $uri = $this->uri($this->chien);
        // Le Dr A et un collègue, chacun sur son poste, sur le même relevé
        $this->patchJson("{$uri}/108", ['states' => ['tartre'], 'note' => null], $this->enTetes($this->jeton))->assertOk();
        ['jeton' => $collegue] = $this->employeAvecJeton($this->clinique);
        $this->patchJson("{$uri}/309", ['states' => ['extraction'], 'note' => 'Faite le 04.11'], $this->enTetes($collegue))->assertOk();
        $this->patchJson("{$uri}/108", ['states' => ['tartre', 'gingivite'], 'note' => null], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.teeth.108.states', ['tartre', 'gingivite'])
            ->assertJsonPath('data.teeth.309.states', ['extraction'])
            ->assertJsonPath('data.teeth.309.note', 'Faite le 04.11');
        // Le bilan s'envoie seul, sans toucher aux dents
        $this->patchJson("{$uri}/bilan", ['gingivitis' => 'mild', 'calculus' => null, 'occlusion' => 'MAL1', 'local_block' => 'Lidocaïne'], $this->enTetes($collegue))
            ->assertOk()
            ->assertJsonPath('data.overview.gingivitis', 'mild')
            ->assertJsonPath('data.overview.occlusion', 'MAL1')
            ->assertJsonPath('data.teeth.108.states', ['tartre', 'gingivite']);
        // Une dent sans état ni note redevient saine ; les autres restent
        $this->patchJson("{$uri}/108", ['states' => [], 'note' => null], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonMissingPath('data.teeth.108')
            ->assertJsonPath('data.teeth.309.states', ['extraction'])
            ->assertJsonPath('data.overview.gingivitis', 'mild');
    }

    public function test_un_changement_du_releve_s_annonce_aux_cliniques_de_l_organisation(): void
    {
        Event::fake([DentalChartChanged::class]);
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->patchJson($this->uri($this->chien).'/108', ['states' => ['tartre'], 'note' => null], $this->enTetes($this->jeton))->assertOk();
        $this->patchJson($this->uri($this->chien).'/bilan', ['gingivitis' => 'mild'], $this->enTetes($this->jeton))->assertOk();
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => $this->bilanVide()], $this->enTetes($this->jeton))->assertOk();

        Event::assertDispatchedTimes(DentalChartChanged::class, 3);
        Event::assertDispatched(DentalChartChanged::class, function (DentalChartChanged $e) use ($soeur): bool {
            $canaux = array_map(fn ($c) => $c->name, $e->broadcastOn());

            return $e->patientId === $this->chien->id
                && in_array('private-clinique.'.$this->clinique->id, $canaux, true)
                && in_array('private-clinique.'.$soeur->id, $canaux, true)
                && $e->broadcastWith() === ['patient_id' => $this->chien->id];
        });
    }

    public function test_l_envoi_d_une_dent_est_valide_strictement(): void
    {
        $entetes = $this->enTetes($this->jeton);
        // Hors de la formule du chat
        $this->patchJson($this->uri($this->chat).'/105', ['states' => ['tartre']], $entetes)->assertUnprocessable()->assertJsonValidationErrors('dent');
        $this->patchJson($this->uri($this->chien).'/999', ['states' => ['tartre']], $entetes)->assertUnprocessable()->assertJsonValidationErrors('dent');
        $this->patchJson($this->uri($this->chien).'/104', ['states' => ['carie']], $entetes)->assertUnprocessable()->assertJsonValidationErrors('states.0');
        $this->patchJson($this->uri($this->chien).'/104', ['states' => ['tartre', 'tartre']], $entetes)->assertUnprocessable()->assertJsonValidationErrors('states.1');
        $this->patchJson($this->uri($this->chien).'/104', ['states' => [], 'note' => str_repeat('a', 501)], $entetes)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->patchJson($this->uri($this->chien).'/104', ['note' => 'sans états'], $entetes)->assertUnprocessable()->assertJsonValidationErrors('states');
        $this->patchJson($this->uri($this->chien).'/bilan', ['gingivitis' => 'heavy'], $entetes)->assertUnprocessable()->assertJsonValidationErrors('gingivitis');
        $this->patchJson($this->uri($this->chien).'/bilan', ['local_block' => str_repeat('a', 201)], $entetes)->assertUnprocessable()->assertJsonValidationErrors('local_block');
        $this->assertSame(0, $this->chien->relevesDentaires()->count());
    }

    public function test_les_envois_par_dent_sont_bornes_a_l_organisation_et_au_droit(): void
    {
        // Une autre organisation : introuvable
        app(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());
        $this->patchJson($this->uri($this->chien).'/108', ['states' => ['tartre']], $this->enTetes($autre))->assertNotFound();
        $this->patchJson($this->uri($this->chien).'/bilan', ['gingivitis' => 'mild'], $this->enTetes($autre))->assertNotFound();

        // Sans le droit : 403, même avec une charge invalide
        app(ContexteClinique::class)->oublier();
        ['employe' => $sansRole, 'jeton' => $sansDroit] = $this->employeAvecJeton($this->clinique);
        $sansRole->update(['role_id' => null]);
        $this->patchJson($this->uri($this->chien).'/108', ['states' => ['carie']], $this->enTetes($sansDroit))->assertForbidden();
        $this->patchJson($this->uri($this->chien).'/bilan', ['gingivitis' => 'heavy'], $this->enTetes($sansDroit))->assertForbidden();
        $this->assertSame(0, $this->chien->relevesDentaires()->count());
    }

    /* ————— 3e vérification (04.11.2026) ————— */

    public function test_reverb_injoignable_ne_fait_jamais_echouer_un_geste_enregistre(): void
    {
        $broadcast = $this->mock(BroadcastFactory::class);
        $broadcast->shouldReceive('queue')->andThrow(new BroadcastException('Reverb injoignable'));

        $this->patchJson($this->uri($this->chien).'/108', ['states' => ['tartre'], 'note' => null], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.teeth.108.states', ['tartre']);
        $this->patchJson($this->uri($this->chien).'/bilan', ['calculus' => 'mild'], $this->enTetes($this->jeton))->assertOk();
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => $this->bilanVide()], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_un_envoi_qui_ne_change_rien_n_ecrit_rien_et_n_annonce_rien(): void
    {
        Event::fake([DentalChartChanged::class]);
        $uri = $this->uri($this->chien);
        $this->patchJson("{$uri}/108", ['states' => ['tartre'], 'note' => null], $this->enTetes($this->jeton))->assertOk();
        $avant = ReleveDentaire::query()->where('dent', 108)->sole()->updated_at;
        $this->travel(5)->minutes();
        $this->patchJson("{$uri}/108", ['states' => ['tartre'], 'note' => null], $this->enTetes($this->jeton))->assertOk();
        $this->patchJson("{$uri}/204", ['states' => [], 'note' => null], $this->enTetes($this->jeton))->assertOk();
        $this->patchJson("{$uri}/bilan", ['calculus' => 'mild'], $this->enTetes($this->jeton))->assertOk();
        $this->patchJson("{$uri}/bilan", ['calculus' => 'mild'], $this->enTetes($this->jeton))->assertOk();

        Event::assertDispatchedTimes(DentalChartChanged::class, 2);
        $this->assertEquals($avant, ReleveDentaire::query()->where('dent', 108)->sole()->updated_at);
    }

    public function test_deux_envois_simultanes_sur_une_dent_jamais_touchee_ne_finissent_pas_en_erreur(): void
    {
        // L'autre envoi crée la ligne entre notre lecture et notre écriture
        $once = false;
        ReleveDentaire::creating(function (ReleveDentaire $releve) use (&$once): void {
            if (! $once) {
                $once = true;
                DB::table('releves_dentaires')->insert(['patient_id' => $releve->patient_id, 'dent' => $releve->dent, 'etats' => json_encode(['gingivite']), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->patchJson($this->uri($this->chien).'/108', ['states' => ['tartre'], 'note' => null], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.teeth.108.states', ['tartre']);
        $this->assertSame(1, ReleveDentaire::query()->where('dent', 108)->count());
    }

    public function test_deux_envois_simultanes_d_un_premier_bilan_ne_finissent_pas_en_erreur(): void
    {
        $once = false;
        DentalOverview::creating(function (DentalOverview $bilan) use (&$once): void {
            if (! $once) {
                $once = true;
                DB::table('dental_overviews')->insert(['patient_id' => $bilan->patient_id, 'gingivitis' => 'severe', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->patchJson($this->uri($this->chien).'/bilan', ['calculus' => 'mild'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.overview.calculus', 'mild');
        $this->assertSame(1, DentalOverview::query()->where('patient_id', $this->chien->id)->count());
    }

    public function test_le_bilan_change_prend_la_provenance_de_qui_l_a_change(): void
    {
        $notre = $this->clinique->societe->organisation_id;
        $autre = Clinique::factory()->create()->societe->organisation_id;
        app(ContexteClinique::class)->oublier();
        DentalOverview::query()->create(['patient_id' => $this->chien->id, 'gingivitis' => 'mild', 'source_organisation_id' => $autre]);

        $this->patchJson($this->uri($this->chien).'/bilan', ['calculus' => 'mild'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame($notre, DentalOverview::query()->where('patient_id', $this->chien->id)->value('source_organisation_id'));

        DentalOverview::query()->where('patient_id', $this->chien->id)->update(['source_organisation_id' => $autre]);
        $this->putJson($this->uri($this->chien), ['teeth' => [], 'overview' => [...$this->bilanVide(), 'occlusion' => 'MAL2']], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame($notre, DentalOverview::query()->where('patient_id', $this->chien->id)->value('source_organisation_id'));
    }
}
