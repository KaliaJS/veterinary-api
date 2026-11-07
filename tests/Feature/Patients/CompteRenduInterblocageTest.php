<?php

namespace Tests\Feature\Patients;

use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Vérification du 07.11.2026 : sous MySQL, l'écriture d'un compte rendu peut
 * être la victime d'un interblocage avec la fin de la consultation (facture
 * puis rendez-vous). Elle est rejouée, jamais une erreur 500. Sans
 * transaction englobante : Laravel ne rejoue jamais une transaction
 * imbriquée. La base, en mémoire, est migrée ici et disparaît avec le test.
 */
class CompteRenduInterblocageTest extends TestCase
{
    use EmetDesJetonsApi;

    /** @var array{restants: int} */
    private array $panne = ['restants' => 0];

    /** La requête après laquelle la panne tombe, une fois */
    private string $cible = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_un_interblocage_est_rejoue_a_l_enregistrement_a_la_ligne_notee_et_a_la_signature(): void
    {
        $clinique = Clinique::factory()->create();
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $clinique->id])->id,
        ]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $uri = "/api/v1/rendez-vous/{$rendezVous->id}/compte-rendu";

        DB::listen(function ($requete): void {
            if ($this->panne['restants'] > 0 && str_contains($requete->sql, 'from "rendez_vous" where "rendez_vous"."id"')) {
                $this->panne['restants']--;
                throw new DeadlockException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        $this->panne['restants'] = 1;
        $this->putJson($uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($jeton))->assertCreated();
        $this->panne['restants'] = 1;
        $this->postJson("{$uri}/outils", ['texte' => 'Fluidothérapie : 120 ml/h'], $this->enTetes($jeton))->assertOk();
        $this->panne['restants'] = 1;
        $this->postJson("{$uri}/signer", ['s' => 'Boiterie.', 'version' => 1], $this->enTetes($jeton))->assertOk();
        $this->assertSame(0, $this->panne['restants']);
    }

    /**
     * La panne au milieu, après les écritures : rejouée, rien n'est écrit deux
     * fois (une seule version gardée, une seule ligne notée, une seule signature).
     */
    public function test_une_panne_apres_les_ecritures_ne_les_ecrit_pas_deux_fois(): void
    {
        $clinique = Clinique::factory()->create();
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $clinique->id])->id,
        ]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $uri = "/api/v1/rendez-vous/{$rendezVous->id}/compte-rendu";
        $this->putJson($uri, ['s' => 'Boiterie.', 'version' => 0], $this->enTetes($jeton))->assertCreated();

        DB::listen(function ($requete): void {
            if ($this->cible !== '' && str_starts_with($requete->sql, $this->cible)) {
                $this->cible = '';
                throw new DeadlockException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        // Après l'écriture de la version remplacée et du texte
        $this->cible = 'update "comptes_rendus" set "s"';
        $this->putJson($uri, ['s' => 'Boiterie depuis 3 jours.', 'version' => 1], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.version', 2);
        // Après l'ajout de la ligne notée
        $this->cible = 'update "comptes_rendus" set "tool_notes"';
        $this->postJson("{$uri}/outils", ['texte' => 'Fluidothérapie : 120 ml/h'], $this->enTetes($jeton))->assertOk();
        // Après la signature
        $this->cible = 'update "comptes_rendus" set "signe_le"';
        $this->postJson("{$uri}/signer", ['s' => 'Boiterie depuis 3 jours.', 'version' => 2], $this->enTetes($jeton))->assertOk();

        $compteRendu = CompteRendu::where('rendez_vous_id', $rendezVous->id)->sole();
        $this->assertSame(1, $compteRendu->versions()->count());
        $this->assertSame(2, $compteRendu->version);
        $this->assertSame('Fluidothérapie : 120 ml/h', $compteRendu->tool_notes);
        $this->assertNotNull($compteRendu->signe_le);
    }
}
