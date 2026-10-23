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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Cliniques sœurs : le laboratoire, l'imagerie et l'hospitalisation sont
 * tenus PAR CLINIQUE. Une clinique sœur ne modifie ni ne complète les
 * analyses, examens et séjours d'à côté (les listes et lectures sont
 * couvertes par les tests d'isolation de chaque domaine). Le dossier
 * patient, lui, est partagé par l'organisation, et d'elle seule.
 */
class IsolationCliniquesSoeursTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_une_analyse_d_une_clinique_soeur_ne_se_modifie_pas(): void
    {
        $analyse = Analyse::factory()->create(['clinique_id' => $this->soeur->id, 'patient_id' => $this->patient->id, 'note' => 'Hémolysé']);

        $this->patchJson("/api/v1/laboratoire/analyses/{$analyse->id}", ['note' => 'Modifiée d’à côté'], $this->enTetes($this->jeton))
            ->assertNotFound();

        $this->assertSame('Hémolysé', Analyse::withoutGlobalScopes()->findOrFail($analyse->id)->note);
    }

    public function test_un_examen_d_une_clinique_soeur_ne_se_modifie_pas(): void
    {
        $examen = ExamenImagerie::factory()->create(['clinique_id' => $this->soeur->id, 'patient_id' => $this->patient->id, 'libelle' => 'Radiographie thorax']);

        $this->patchJson("/api/v1/imagerie/examens/{$examen->id}", ['libelle' => 'Modifié d’à côté'], $this->enTetes($this->jeton))
            ->assertNotFound();

        $this->assertSame('Radiographie thorax', ExamenImagerie::withoutGlobalScopes()->findOrFail($examen->id)->libelle);
    }

    public function test_un_sejour_d_une_clinique_soeur_ne_recoit_ni_soin_ni_constante_et_ne_montre_pas_sa_facture(): void
    {
        $sejour = Sejour::factory()->create(['clinique_id' => $this->soeur->id, 'patient_id' => $this->patient->id]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins", ['prevu_a' => 600, 'acte' => 'Perfusion'], $entetes)
            ->assertNotFound();
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/constantes", ['mesure_a' => 600, 'temperature' => 38.5, 'fc' => 90, 'fr' => 20], $entetes)
            ->assertNotFound();
        $this->getJson("/api/v1/hospitalisation/sejours/{$sejour->id}/facture", $entetes)->assertNotFound();

        $this->assertSame(0, SejourSoin::query()->where('sejour_id', $sejour->id)->count());
        $this->assertSame(0, SejourConstante::query()->where('sejour_id', $sejour->id)->count());
    }

    public function test_le_dossier_d_un_patient_d_une_autre_organisation_est_introuvable(): void
    {
        $etrangere = Clinique::factory()->create();
        $etranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $etrangere->societe->organisation_id]))
            ->create();
        Analyse::factory()->create(['clinique_id' => $etrangere->id, 'patient_id' => $etranger->id]);
        ExamenImagerie::factory()->create(['clinique_id' => $etrangere->id, 'patient_id' => $etranger->id]);

        $this->getJson("/api/v1/patients/{$etranger->id}/analyses", $this->enTetes($this->jeton))->assertNotFound();
        $this->getJson("/api/v1/patients/{$etranger->id}/imagerie", $this->enTetes($this->jeton))->assertNotFound();
    }

    public function test_le_dossier_partage_montre_les_analyses_et_examens_de_la_soeur_sans_rouvrir_ses_gestes(): void
    {
        $analyse = Analyse::factory()->create(['clinique_id' => $this->soeur->id, 'patient_id' => $this->patient->id]);
        $examen = ExamenImagerie::factory()->create(['clinique_id' => $this->soeur->id, 'patient_id' => $this->patient->id]);
        $entetes = $this->enTetes($this->jeton);

        $this->getJson("/api/v1/patients/{$this->patient->id}/analyses", $entetes)
            ->assertOk()
            ->assertJsonPath('data.0.id', $analyse->id);
        $this->getJson("/api/v1/patients/{$this->patient->id}/imagerie", $entetes)
            ->assertOk()
            ->assertJsonPath('data.0.id', $examen->id);

        // Lire le dossier ne lève le scope que pour cette lecture : la requête suivante reste bornée
        $this->getJson("/api/v1/laboratoire/analyses/{$analyse->id}", $entetes)->assertNotFound();
        $this->getJson("/api/v1/imagerie/examens/{$examen->id}", $entetes)->assertNotFound();
    }
}
