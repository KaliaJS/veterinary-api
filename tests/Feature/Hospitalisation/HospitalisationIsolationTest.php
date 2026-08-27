<?php

namespace Tests\Feature\Hospitalisation;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\Sejour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les séjours sont PAR CLINIQUE (le box est un lit physique) : une
 * clinique sœur ne voit ni ne touche ceux d'à côté. Les clés
 * étrangères restent bornées — patient à l'organisation, vétérinaire
 * référent aux cliniques de l'organisation (comme les signataires du
 * dossier).
 */
class HospitalisationIsolationTest extends TestCase
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

        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'nombre_boxes' => 6]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();
    }

    public function test_les_sejours_d_une_clinique_soeur_sont_invisibles(): void
    {
        Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $ailleurs = Sejour::factory()->create(['clinique_id' => $this->cliniqueSoeur->id, 'patient_id' => $this->patient->id]);

        $this->getJson('/api/v1/hospitalisation/sejours', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/hospitalisation/sejours/{$ailleurs->id}", $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_les_gestes_sur_un_sejour_d_une_clinique_soeur_sont_introuvables(): void
    {
        $ailleurs = Sejour::factory()->create(['clinique_id' => $this->cliniqueSoeur->id, 'patient_id' => $this->patient->id]);
        $soin = $ailleurs->soins()->create(['prevu_a' => 600, 'acte' => 'Contrôle']);

        $entetes = $this->enTetes($this->jeton);

        $this->patchJson("/api/v1/hospitalisation/sejours/{$ailleurs->id}", ['note' => 'x'], $entetes)->assertNotFound();
        $this->postJson("/api/v1/hospitalisation/sejours/{$ailleurs->id}/soins/{$soin->id}/faire", [], $entetes)->assertNotFound();
        $this->postJson("/api/v1/hospitalisation/sejours/{$ailleurs->id}/sortie", [], $entetes)->assertNotFound();
    }

    public function test_l_admission_est_rattachee_a_la_clinique_du_jeton(): void
    {
        $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $this->patient->id,
            'box' => 1,
            'motif' => 'Surveillance',
        ], $this->enTetes($this->jeton))->assertCreated();

        $this->assertSame($this->clinique->id, Sejour::withoutGlobalScopes()->sole()->clinique_id);
    }

    public function test_un_box_occupe_dans_une_clinique_soeur_n_empeche_rien_ici(): void
    {
        Sejour::factory()->create(['clinique_id' => $this->cliniqueSoeur->id, 'patient_id' => $this->patient->id, 'box' => 2]);

        $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $this->patient->id,
            'box' => 2,
            'motif' => 'Surveillance',
        ], $this->enTetes($this->jeton))->assertCreated();
    }

    public function test_un_patient_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->cliniqueEtrangere->societe->organisation_id]))
            ->create();

        $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $etranger->id,
            'box' => 1,
            'motif' => 'Surveillance',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_un_veterinaire_d_une_clinique_soeur_est_accepte_comme_referent(): void
    {
        $confrere = Employe::factory()->create(['clinique_id' => $this->cliniqueSoeur->id]);

        $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $this->patient->id,
            'box' => 1,
            'motif' => 'Surveillance',
            'veterinaire_id' => $confrere->id,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.veterinaire_id', $confrere->id)
            ->assertJsonPath('data.veterinaire.nom', $confrere->nom);
    }

    public function test_un_veterinaire_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Employe::factory()->create(['clinique_id' => $this->cliniqueEtrangere->id]);

        $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $this->patient->id,
            'box' => 1,
            'motif' => 'Surveillance',
            'veterinaire_id' => $etranger->id,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('veterinaire_id');
    }
}
