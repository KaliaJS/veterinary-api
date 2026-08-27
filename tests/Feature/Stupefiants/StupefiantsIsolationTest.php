<?php

namespace Tests\Feature\Stupefiants;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le registre des stupéfiants est tenu PAR CLINIQUE : contrairement au
 * journal de stock (lecture transversale), le journal, les soldes et
 * l'export ne montrent que la clinique du jeton — une clinique sœur y
 * tient le sien. Les clés étrangères (produit, patient) restent
 * bornées à l'organisation.
 */
class StupefiantsIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueEtrangere;

    private Employe $employe;

    private string $jeton;

    private Produit $substance;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $organisation = $this->clinique->societe->organisation_id;
        $this->substance = Produit::factory()->stupefiant()->create(['organisation_id' => $organisation]);
        $this->patient = Patient::factory()->create(['organisation_id' => $organisation]);
    }

    public function test_le_journal_d_une_clinique_soeur_est_invisible(): void
    {
        MouvementStupefiant::factory()->create([
            'clinique_id' => $this->clinique->id,
            'produit_id' => $this->substance->id,
            'quantite' => 25,
        ]);
        MouvementStupefiant::factory()->create([
            'clinique_id' => $this->cliniqueSoeur->id,
            'produit_id' => $this->substance->id,
            'quantite' => 100,
        ]);

        $this->getJson('/api/v1/stupefiants/mouvements', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/stupefiants/registre', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.mouvements');
    }

    public function test_le_solde_est_borne_a_la_clinique_du_jeton(): void
    {
        MouvementStupefiant::factory()->create([
            'clinique_id' => $this->clinique->id,
            'produit_id' => $this->substance->id,
            'quantite' => 30,
        ]);
        MouvementStupefiant::factory()->create([
            'clinique_id' => $this->cliniqueSoeur->id,
            'produit_id' => $this->substance->id,
            'quantite' => 100,
        ]);

        $this->getJson('/api/v1/stupefiants/soldes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.solde', 30);
    }

    public function test_un_produit_stupefiant_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Produit::factory()->stupefiant()->create([
            'organisation_id' => $this->cliniqueEtrangere->societe->organisation_id,
        ]);

        $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $etranger->id,
            'quantite' => 5,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('produit_id');
    }

    public function test_un_patient_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Patient::factory()->create([
            'organisation_id' => $this->cliniqueEtrangere->societe->organisation_id,
        ]);

        $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $this->substance->id,
            'quantite' => -0.4,
            'patient_id' => $etranger->id,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_un_mouvement_cree_est_rattache_a_la_clinique_et_signe_par_l_employe_du_jeton(): void
    {
        $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $this->substance->id,
            'quantite' => 20,
        ], $this->enTetes($this->jeton))->assertCreated();

        $mouvement = MouvementStupefiant::withoutGlobalScopes()->sole();

        $this->assertSame($this->clinique->id, $mouvement->clinique_id);
        $this->assertSame($this->employe->id, $mouvement->employe_id);
    }
}
