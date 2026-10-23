<?php

namespace Tests\Feature;

use App\Models\Analyse;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\ExamenImagerie;
use App\Models\Patient;
use App\Models\Sejour;
use App\Models\SejourConstante;
use App\Models\SejourSoin;
use App\Models\SessionTeleconsultation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Lecture du détail des fiches tenues par clinique (séjour, analyse,
 * examen d'imagerie, téléconsultation) : jusqu'ici, seuls les refus
 * (autre clinique, droit manquant) étaient vérifiés ; ici, la clinique du
 * jeton lit sa fiche avec le patient et ce qui s'y rattache.
 */
class DetailDesFichesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Rochat']))
            ->create(['nom' => 'Moka']);
    }

    public function test_un_sejour_se_lit_avec_son_patient_ses_soins_et_ses_constantes(): void
    {
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'motif' => 'Surveillance post-anesthésie', 'box' => 3]);
        SejourSoin::factory()->create(['sejour_id' => $sejour->id, 'acte' => 'Contrôle pansement', 'prevu_a' => 600]);
        SejourConstante::factory()->create(['sejour_id' => $sejour->id, 'temperature' => 38.6]);

        $this->getJson("/api/v1/hospitalisation/sejours/{$sejour->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $sejour->id)
            ->assertJsonPath('data.motif', 'Surveillance post-anesthésie')
            ->assertJsonPath('data.box', 3)
            ->assertJsonPath('data.patient.nom', 'Moka')
            ->assertJsonPath('data.veterinaire', null)
            ->assertJsonCount(1, 'data.soins')
            ->assertJsonPath('data.soins.0.acte', 'Contrôle pansement')
            ->assertJsonCount(1, 'data.constantes');
    }

    public function test_une_analyse_se_lit_avec_son_patient_et_son_prescripteur(): void
    {
        $analyse = Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);

        $this->getJson("/api/v1/laboratoire/analyses/{$analyse->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $analyse->id)
            ->assertJsonPath('data.numero', $analyse->numero)
            ->assertJsonPath('data.statut', 'attente')
            ->assertJsonPath('data.patient.nom', 'Moka')
            ->assertJsonPath('data.prescripteur.id', $analyse->prescripteur_id)
            ->assertJsonPath('data.valide_par', null)
            ->assertJsonStructure(['data' => ['panels' => [['code', 'nom', 'tube']], 'resultats']]);
    }

    public function test_un_examen_d_imagerie_se_lit_avec_son_patient_et_ses_cliches(): void
    {
        $examen = ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'libelle' => 'Radiographie thorax']);

        $this->getJson("/api/v1/imagerie/examens/{$examen->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $examen->id)
            ->assertJsonPath('data.libelle', 'Radiographie thorax')
            ->assertJsonPath('data.est_signe', false)
            ->assertJsonPath('data.patient.nom', 'Moka')
            ->assertJsonPath('data.prescripteur.id', $examen->prescripteur_id)
            ->assertJsonPath('data.cliches', []);
    }

    public function test_une_teleconsultation_se_lit_avec_son_patient_et_qui_l_a_demarree(): void
    {
        $session = SessionTeleconsultation::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'motif' => 'Contrôle de plaie']);

        $this->getJson("/api/v1/tele/sessions/{$session->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.motif', 'Contrôle de plaie')
            ->assertJsonPath('data.est_terminee', false)
            ->assertJsonPath('data.duree_minutes', null)
            ->assertJsonPath('data.patient.nom', 'Moka')
            ->assertJsonPath('data.demarre_par.id', $session->demarre_par_id);
    }
}
