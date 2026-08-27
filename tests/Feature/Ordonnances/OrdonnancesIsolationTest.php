<?php

namespace Tests\Feature\Ordonnances;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'ordonnance suit le patient : NIVEAU ORGANISATION. Elle est visible
 * de toutes les cliniques de l'organisation, invisible d'une
 * organisation étrangère ; elle ne référence jamais un patient d'une
 * autre organisation.
 */
class OrdonnancesIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueEtrangere;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_une_ordonnance_d_une_autre_organisation_est_invisible(): void
    {
        $etrangere = Ordonnance::factory()->create(['clinique_id' => $this->cliniqueEtrangere->id]);

        $this->getJson('/api/v1/ordonnances', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/ordonnances/{$etrangere->id}", $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_une_ordonnance_d_une_clinique_soeur_est_visible(): void
    {
        $soeur = Ordonnance::factory()->create(['clinique_id' => $this->cliniqueSoeur->id]);

        $this->getJson("/api/v1/ordonnances/{$soeur->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $soeur->id);
    }

    public function test_une_ordonnance_ne_reference_pas_un_patient_d_une_autre_organisation(): void
    {
        $patientEtranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->cliniqueEtrangere->societe->organisation_id]))
            ->create();

        $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $patientEtranger->id,
            'lignes' => [['produit_id' => 999, 'posologie' => 'Refusé']],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_une_signature_est_rattachee_a_l_organisation_et_a_la_clinique_du_jeton(): void
    {
        $organisation = $this->clinique->societe->organisation_id;
        $patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $organisation]))
            ->create();
        $medicament = Produit::factory()->medicament()->create(['organisation_id' => $organisation]);

        $reponse = $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $patient->id,
            'lignes' => [['produit_id' => $medicament->id, 'posologie' => '1 cp']],
        ], $this->enTetes($this->jeton))->assertCreated();

        $ordonnance = Ordonnance::withoutGlobalScopes()->find($reponse->json('data.id'));

        $this->assertSame($organisation, $ordonnance->organisation_id);
        $this->assertSame($this->clinique->id, $ordonnance->clinique_id);
    }
}
