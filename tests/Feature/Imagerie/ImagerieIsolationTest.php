<?php

namespace Tests\Feature\Imagerie;

use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'imagerie est PAR CLINIQUE : la worklist et les gestes sont ceux de
 * la clinique du jeton, le RDV lié doit être le sien. La fiche
 * patient, elle, lit le dossier partagé : examens de toutes les
 * cliniques de l'organisation — et d'elles seules.
 */
class ImagerieIsolationTest extends TestCase
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

    /** @return array<string, mixed> */
    private function chargeValide(array $surcharges = []): array
    {
        return [
            'patient_id' => $this->patient->id,
            'modalite' => 'DX',
            'libelle' => 'Radiographie thorax',
            'realise_le' => now()->toISOString(),
            ...$surcharges,
        ];
    }

    public function test_les_examens_d_une_clinique_soeur_sont_invisibles_de_la_worklist(): void
    {
        ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $ailleurs = ExamenImagerie::factory()->create([
            'clinique_id' => $this->cliniqueSoeur->id,
            'patient_id' => $this->patient->id,
        ]);

        $entetes = $this->enTetes($this->jeton);

        $this->getJson('/api/v1/imagerie/examens', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/imagerie/examens/{$ailleurs->id}", $entetes)->assertNotFound();
        $this->postJson("/api/v1/imagerie/examens/{$ailleurs->id}/compte-rendu", ['constatations' => 'x'], $entetes)->assertNotFound();
        $this->postJson("/api/v1/imagerie/examens/{$ailleurs->id}/cliches", ['sop_instance_uid' => '1.2.3'], $entetes)->assertNotFound();
    }

    public function test_l_examen_est_rattache_a_la_clinique_du_jeton(): void
    {
        $this->postJson('/api/v1/imagerie/examens', $this->chargeValide(), $this->enTetes($this->jeton))
            ->assertCreated();

        $this->assertSame($this->clinique->id, ExamenImagerie::withoutGlobalScopes()->sole()->clinique_id);
    }

    public function test_un_rdv_d_une_clinique_soeur_ne_se_lie_pas(): void
    {
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->cliniqueSoeur->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->cliniqueSoeur->id])->id,
            'type' => TypeRendezVous::Imagerie,
        ]);

        $this->postJson('/api/v1/imagerie/examens', $this->chargeValide(['rendez_vous_id' => $rendezVous->id]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rendez_vous_id');
    }

    public function test_un_patient_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->cliniqueEtrangere->societe->organisation_id]))
            ->create();

        $this->postJson('/api/v1/imagerie/examens', $this->chargeValide(['patient_id' => $etranger->id]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_un_operateur_d_une_autre_organisation_est_refuse(): void
    {
        $etranger = Employe::factory()->dans($this->cliniqueEtrangere)->create();

        $this->postJson('/api/v1/imagerie/examens', $this->chargeValide(['prescripteur_id' => $etranger->id]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prescripteur_id');
    }

    public function test_la_fiche_patient_lit_les_examens_de_toutes_les_cliniques_de_l_organisation(): void
    {
        ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        ExamenImagerie::factory()->create(['clinique_id' => $this->cliniqueSoeur->id, 'patient_id' => $this->patient->id]);

        $this->getJson("/api/v1/patients/{$this->patient->id}/imagerie", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
