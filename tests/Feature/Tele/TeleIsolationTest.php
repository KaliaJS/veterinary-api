<?php

namespace Tests\Feature\Tele;

use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\SessionTeleconsultation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les sessions de téléconsultation sont PAR CLINIQUE : celles d'une
 * clinique sœur sont invisibles, le RDV lié doit être celui de la
 * clinique du jeton, le patient reste borné à l'organisation.
 */
class TeleIsolationTest extends TestCase
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

    public function test_les_sessions_d_une_clinique_soeur_sont_invisibles(): void
    {
        SessionTeleconsultation::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
        ]);
        $ailleurs = SessionTeleconsultation::factory()->create([
            'clinique_id' => $this->cliniqueSoeur->id,
            'patient_id' => $this->patient->id,
        ]);

        $entetes = $this->enTetes($this->jeton);

        $this->getJson('/api/v1/tele/sessions', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/tele/sessions/{$ailleurs->id}", $entetes)->assertNotFound();
        $this->postJson("/api/v1/tele/sessions/{$ailleurs->id}/terminer", ['cloturer' => false], $entetes)->assertNotFound();
    }

    public function test_la_session_est_rattachee_a_la_clinique_du_jeton(): void
    {
        $this->postJson('/api/v1/tele/sessions', [
            'patient_id' => $this->patient->id,
        ], $this->enTetes($this->jeton))->assertCreated();

        $this->assertSame($this->clinique->id, SessionTeleconsultation::withoutGlobalScopes()->sole()->clinique_id);
    }

    public function test_un_rdv_d_une_clinique_soeur_ne_se_lie_pas(): void
    {
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->cliniqueSoeur->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->cliniqueSoeur->id])->id,
            'type' => TypeRendezVous::Teleconsultation,
        ]);

        $this->postJson('/api/v1/tele/sessions', ['rendez_vous_id' => $rendezVous->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rendez_vous_id');
    }

    public function test_un_patient_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->cliniqueEtrangere->societe->organisation_id]))
            ->create();

        $this->postJson('/api/v1/tele/sessions', ['patient_id' => $etranger->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }
}
