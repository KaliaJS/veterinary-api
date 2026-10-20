<?php

namespace Tests\Feature\Bloc;

use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Intervention;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Salle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le bloc est PAR CLINIQUE : les interventions d'une clinique sœur
 * sont invisibles, la salle et le RDV lié doivent être ceux de la
 * clinique du jeton. Le patient reste borné à l'organisation, le
 * chirurgien aux cliniques de l'organisation.
 */
class BlocIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueEtrangere;

    private Employe $chirurgien;

    private string $jeton;

    private Patient $patient;

    private Salle $salle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['employe' => $this->chirurgien, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();

        $this->salle = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id]);
    }

    /** @return array<string, mixed> */
    private function chargeValide(array $surcharges = []): array
    {
        return [
            'patient_id' => $this->patient->id,
            'procedure' => 'Cystotomie',
            'prevu_le' => now()->toISOString(),
            'duree_minutes' => 60,
            'salle_id' => $this->salle->id,
            'chirurgien_id' => $this->chirurgien->id,
            ...$surcharges,
        ];
    }

    public function test_les_interventions_d_une_clinique_soeur_sont_invisibles(): void
    {
        Intervention::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'chirurgien_id' => $this->chirurgien->id,
        ]);
        $ailleurs = Intervention::factory()->create(['clinique_id' => $this->cliniqueSoeur->id]);

        $this->getJson('/api/v1/bloc/interventions', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $entetes = $this->enTetes($this->jeton);
        $this->getJson("/api/v1/bloc/interventions/{$ailleurs->id}", $entetes)->assertNotFound();
        $this->postJson("/api/v1/bloc/interventions/{$ailleurs->id}/avancer", [], $entetes)->assertNotFound();
        $this->putJson("/api/v1/bloc/interventions/{$ailleurs->id}/checklist/jeun", ['est_coche' => true], $entetes)->assertNotFound();
    }

    public function test_la_programmation_est_rattachee_a_la_clinique_du_jeton(): void
    {
        $this->postJson('/api/v1/bloc/interventions', $this->chargeValide(), $this->enTetes($this->jeton))
            ->assertCreated();

        $this->assertSame($this->clinique->id, Intervention::withoutGlobalScopes()->sole()->clinique_id);
    }

    public function test_une_salle_d_une_clinique_soeur_est_refusee(): void
    {
        $ailleurs = Salle::factory()->bloc()->create(['clinique_id' => $this->cliniqueSoeur->id]);

        $this->postJson('/api/v1/bloc/interventions', $this->chargeValide(['salle_id' => $ailleurs->id]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('salle_id');
    }

    public function test_une_salle_de_consultation_est_refusee(): void
    {
        $consultation = Salle::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->postJson('/api/v1/bloc/interventions', $this->chargeValide(['salle_id' => $consultation->id]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('salle_id');
    }

    public function test_un_rdv_d_une_clinique_soeur_ne_se_lie_pas(): void
    {
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->cliniqueSoeur->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->cliniqueSoeur->id])->id,
            'type' => TypeRendezVous::Chirurgie,
        ]);

        $this->postJson('/api/v1/bloc/interventions', $this->chargeValide(['rendez_vous_id' => $rendezVous->id]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rendez_vous_id');
    }

    public function test_un_patient_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->cliniqueEtrangere->societe->organisation_id]))
            ->create();

        $this->postJson('/api/v1/bloc/interventions', $this->chargeValide(['patient_id' => $etranger->id]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_un_chirurgien_d_une_clinique_soeur_est_accepte(): void
    {
        $confrere = Employe::factory()->dans($this->cliniqueSoeur)->create();

        $this->postJson('/api/v1/bloc/interventions', $this->chargeValide(['chirurgien_id' => $confrere->id]), $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.chirurgien.nom', $confrere->nom);
    }

    public function test_un_chirurgien_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Employe::factory()->dans($this->cliniqueEtrangere)->create();

        $this->postJson('/api/v1/bloc/interventions', $this->chargeValide(['chirurgien_id' => $etranger->id]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chirurgien_id');
    }
}
