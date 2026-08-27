<?php

namespace Tests\Feature\Laboratoire;

use App\Models\Analyse;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le laboratoire est PAR CLINIQUE : la worklist et les gestes sont
 * ceux de la clinique du jeton, les demandes d'une clinique sœur y
 * sont invisibles. La fiche patient, elle, lit le dossier partagé :
 * analyses de toutes les cliniques de l'organisation — et d'elles
 * seules.
 */
class LaboratoireIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueEtrangere;

    private string $jeton;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();
    }

    public function test_les_analyses_d_une_clinique_soeur_sont_invisibles_de_la_worklist(): void
    {
        Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $ailleurs = Analyse::factory()->create([
            'clinique_id' => $this->cliniqueSoeur->id,
            'patient_id' => $this->patient->id,
        ]);

        $entetes = $this->enTetes($this->jeton);

        $this->getJson('/api/v1/laboratoire/analyses', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/laboratoire/analyses/{$ailleurs->id}", $entetes)->assertNotFound();
        $this->postJson("/api/v1/laboratoire/analyses/{$ailleurs->id}/avancer", [], $entetes)->assertNotFound();
        $this->putJson("/api/v1/laboratoire/analyses/{$ailleurs->id}/resultats", [
            'resultats' => [['id' => 1, 'valeur' => 1]],
        ], $entetes)->assertNotFound();
    }

    public function test_la_demande_est_rattachee_a_la_clinique_du_jeton(): void
    {
        $this->postJson('/api/v1/laboratoire/analyses', [
            'patient_id' => $this->patient->id,
            'panels' => ['nfs'],
        ], $this->enTetes($this->jeton))->assertCreated();

        $this->assertSame($this->clinique->id, Analyse::withoutGlobalScopes()->sole()->clinique_id);
    }

    public function test_un_patient_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->cliniqueEtrangere->societe->organisation_id]))
            ->create();

        $this->postJson('/api/v1/laboratoire/analyses', [
            'patient_id' => $etranger->id,
            'panels' => ['nfs'],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_la_fiche_patient_lit_les_analyses_de_toutes_les_cliniques_de_l_organisation(): void
    {
        Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        Analyse::factory()->create(['clinique_id' => $this->cliniqueSoeur->id, 'patient_id' => $this->patient->id]);

        $this->getJson("/api/v1/patients/{$this->patient->id}/analyses", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_le_referentiel_de_panels_est_celui_de_l_organisation_du_jeton(): void
    {
        // La clinique étrangère a son propre référentiel (6 panels
        // aussi) : la liste servie n'en montre que 6, pas 12.
        $this->getJson('/api/v1/laboratoire/panels', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(6, 'data');
    }
}
