<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\Visite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Historique des visites d'un patient, parcours nominal : la liste du plus
 * récent au plus ancien avec le nom du vétérinaire (même d'une clinique
 * sœur, le dossier est partagé), une visite rétroactive qui la rejoint ;
 * jamais une visite datée de demain ni sans motif.
 */
class VisitesParcoursTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    private Employe $employe;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Anna Meyer']);
    }

    public function test_l_historique_se_lit_du_plus_recent_au_plus_ancien_avec_le_veterinaire(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $collegue = Employe::factory()->dans($soeur)->create(['nom' => 'Dr Paul Martin']);
        $ancienne = Visite::factory()->create(['patient_id' => $this->patient->id, 'visite_le' => aujourdhui()->subYear(), 'employe_id' => null, 'motif' => 'Vaccination annuelle']);
        $recente = Visite::factory()->create(['patient_id' => $this->patient->id, 'visite_le' => aujourdhui()->subWeek(), 'employe_id' => $collegue->id, 'motif' => 'Otite']);
        Visite::factory()->create(['patient_id' => Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id])->id]);

        $this->getJson("/api/v1/patients/{$this->patient->id}/visites", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $recente->id)
            ->assertJsonPath('data.0.motif', 'Otite')
            ->assertJsonPath('data.0.visite_le', aujourdhui()->subWeek()->toDateString())
            ->assertJsonPath('data.0.employe_id', $collegue->id)
            ->assertJsonPath('data.0.veterinaire', 'Dr Paul Martin')
            ->assertJsonPath('data.1.id', $ancienne->id)
            ->assertJsonPath('data.1.veterinaire', null);
    }

    public function test_une_visite_retroactive_rejoint_l_historique_a_sa_date(): void
    {
        Visite::factory()->create(['patient_id' => $this->patient->id, 'visite_le' => aujourdhui()->subDay(), 'motif' => 'Contrôle']);
        $entetes = $this->enTetes($this->jeton);

        $id = $this->postJson("/api/v1/patients/{$this->patient->id}/visites", [
            'motif' => 'Dermatite',
            'visite_le' => aujourdhui()->subDays(10)->toDateString(),
            'note' => 'Prurit diminué.',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.visite_le', aujourdhui()->subDays(10)->toDateString())
            ->assertJsonPath('data.note', 'Prurit diminué.')
            ->json('data.id');

        $this->getJson("/api/v1/patients/{$this->patient->id}/visites", $entetes)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $id)
            ->assertJsonPath('data.1.veterinaire', 'Dr Anna Meyer');
    }

    public function test_une_clinique_soeur_lit_la_visite_inscrite_ici(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $id = $this->postJson("/api/v1/patients/{$this->patient->id}/visites", ['motif' => 'Boiterie'], $this->enTetes($this->jeton))
            ->assertCreated()
            ->json('data.id');

        ['jeton' => $jetonSoeur] = $this->employeAvecJeton($soeur);

        $this->getJson("/api/v1/patients/{$this->patient->id}/visites", $this->enTetes($jetonSoeur))
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.veterinaire', 'Dr Anna Meyer');
    }

    public function test_une_visite_de_demain_ou_sans_motif_est_refusee(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/patients/{$this->patient->id}/visites", [
            'motif' => 'Contrôle',
            'visite_le' => aujourdhui()->addDay()->toDateString(),
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('visite_le');

        $this->postJson("/api/v1/patients/{$this->patient->id}/visites", ['note' => 'Sans motif'], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motif');

        $this->postJson("/api/v1/patients/{$this->patient->id}/visites", ['motif' => str_repeat('x', 256)], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motif');

        $this->assertSame(0, $this->patient->visites()->count());
    }
}
