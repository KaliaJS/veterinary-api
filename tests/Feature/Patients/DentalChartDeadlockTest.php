<?php

namespace Tests\Feature\Patients;

use App\Enums\Espece;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DentalOverview;
use App\Models\Patient;
use App\Models\ReleveDentaire;
use Illuminate\Database\QueryException;
use PDOException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Schéma dentaire, 4e vérification (04.11.2026) : sous MySQL, deux premières
 * écritures sur une même dent (ou un même bilan) peuvent s'interbloquer
 * (1213, SQLSTATE 40001). La transaction se rejoue seule : la seconde
 * tentative réussit, pas de 500. Sans la transaction englobante de
 * RefreshDatabase, qui empêcherait Laravel de rejouer.
 */
class DentalChartDeadlockTest extends TestCase
{
    use EmetDesJetonsApi;

    private Patient $chien;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        // Jamais une vraie base : cette classe migre sans transaction englobante
        $connexion = (string) config('database.default');
        if ($connexion !== 'sqlite' || config("database.connections.{$connexion}.database") !== ':memory:') {
            $this->markTestSkipped('Ces essais ne migrent qu’une base SQLite en mémoire (:memory:).');
        }
        // Une base en mémoire neuve, migrée pour ce test, sans transaction englobante
        $this->artisan('migrate', ['--force' => true]);

        $clinique = Clinique::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
        $this->chien = Patient::factory()->for($client)->create(['espece' => Espece::Chien]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($clinique);
    }

    public function test_la_classe_refuse_de_migrer_une_autre_base_que_sqlite_en_memoire(): void
    {
        $source = (string) file_get_contents(__FILE__);
        $debut = (int) strpos($source, 'protected function setUp');
        $setUp = substr($source, $debut, (int) strpos($source, "\n    }\n", $debut) - $debut);
        // La garde passe avant la migration
        $this->assertNotFalse(strpos($setUp, 'markTestSkipped'));
        $this->assertLessThan(strpos($setUp, "artisan('migrate'"), strpos($setUp, ':memory:'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    private static function interblocage(): QueryException
    {
        return new QueryException('mysql', 'insert into releves_dentaires', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001));
    }

    public function test_un_interblocage_sur_une_dent_se_rejoue_et_reussit(): void
    {
        $fois = 0;
        ReleveDentaire::creating(function () use (&$fois): void {
            if ($fois++ === 0) {
                throw self::interblocage();
            }
        });

        $this->patchJson("/api/v1/patients/{$this->chien->id}/dents/108", ['states' => ['tartre'], 'note' => null], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.teeth.108.states', ['tartre']);
        $this->assertSame(2, $fois);
    }

    public function test_un_interblocage_sur_le_bilan_se_rejoue_et_reussit(): void
    {
        $fois = 0;
        DentalOverview::creating(function () use (&$fois): void {
            if ($fois++ === 0) {
                throw self::interblocage();
            }
        });

        $this->patchJson("/api/v1/patients/{$this->chien->id}/dents/bilan", ['calculus' => 'mild'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.overview.calculus', 'mild');
        $this->assertSame(2, $fois);
    }

    public function test_un_interblocage_pendant_le_releve_entier_se_rejoue_et_reussit(): void
    {
        $fois = 0;
        ReleveDentaire::creating(function () use (&$fois): void {
            if ($fois++ === 0) {
                throw self::interblocage();
            }
        });

        $this->putJson("/api/v1/patients/{$this->chien->id}/dents", ['teeth' => ['108' => ['states' => ['tartre']]], 'overview' => ['gingivitis' => null, 'calculus' => null, 'occlusion' => null, 'local_block' => null]], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.teeth.108.states', ['tartre']);
    }
}
