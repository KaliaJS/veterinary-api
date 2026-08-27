<?php

namespace Tests\Feature\Tele;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\RendezVousAudit;
use App\Models\SessionTeleconsultation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Sessions de téléconsultation (tele.js) — même patron que le bloc :
 * la session est le dossier maître, le RDV `tele` lié n'est que sa
 * projection planning. Démarrage → RDV cours ; « Terminer » → durée
 * servie, RDV fini + facture de l'acte ; « Quitter » → le rendez-vous
 * reste en cours et l'appel peut reprendre. Jamais de suppression.
 */
class SessionTeleconsultationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();
    }

    /** @return array<string, string> */
    private function jeton(): array
    {
        return $this->enTetes($this->jeton);
    }

    private function rendezVousTele(array $attributs = []): RendezVous
    {
        return RendezVous::factory()->pourPatient($this->patient)->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Teleconsultation,
            'reason' => 'Suivi dermatologique',
            ...$attributs,
        ]);
    }

    public function test_le_demarrage_depuis_un_rdv_le_passe_en_cours_et_derive_patient_et_motif(): void
    {
        $rendezVous = $this->rendezVousTele();

        $this->postJson('/api/v1/tele/sessions', [
            'rendez_vous_id' => $rendezVous->id,
        ], $this->jeton())
            ->assertCreated()
            ->assertJsonPath('data.est_terminee', false)
            ->assertJsonPath('data.patient_id', $this->patient->id)
            ->assertJsonPath('data.motif', 'Suivi dermatologique')
            ->assertJsonPath('data.demarre_par.id', $this->employe->id);

        $this->assertSame(StatutRendezVous::Cours, $rendezVous->refresh()->status);

        // La projection est auditée comme un geste planning.
        $this->assertTrue(
            RendezVousAudit::where('rendez_vous_id', $rendezVous->id)->where('champ', 'status')->exists(),
        );
    }

    public function test_l_appel_libre_vise_un_patient_sans_toucher_au_planning(): void
    {
        $this->postJson('/api/v1/tele/sessions', [
            'patient_id' => $this->patient->id,
            'motif' => 'Conseil post-opératoire',
        ], $this->jeton())
            ->assertCreated()
            ->assertJsonPath('data.rendez_vous_id', null)
            ->assertJsonPath('data.motif', 'Conseil post-opératoire');

        $this->postJson('/api/v1/tele/sessions', [], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_une_seule_session_en_cours_par_rendez_vous(): void
    {
        $rendezVous = $this->rendezVousTele();

        $this->postJson('/api/v1/tele/sessions', ['rendez_vous_id' => $rendezVous->id], $this->jeton())
            ->assertCreated();

        $this->postJson('/api/v1/tele/sessions', ['rendez_vous_id' => $rendezVous->id], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rendez_vous_id');
    }

    public function test_un_rdv_annule_ou_d_un_autre_type_ne_demarre_pas_d_appel(): void
    {
        $annule = $this->rendezVousTele(['status' => StatutRendezVous::Annule]);
        $consultation = RendezVous::factory()->pourPatient($this->patient)->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
        ]);

        foreach ([$annule, $consultation] as $rendezVous) {
            $this->postJson('/api/v1/tele/sessions', ['rendez_vous_id' => $rendezVous->id], $this->jeton())
                ->assertUnprocessable()
                ->assertJsonValidationErrors('rendez_vous_id');
        }
    }

    public function test_quitter_fige_la_session_et_laisse_le_rdv_en_cours(): void
    {
        $rendezVous = $this->rendezVousTele();
        $reponse = $this->postJson('/api/v1/tele/sessions', ['rendez_vous_id' => $rendezVous->id], $this->jeton());
        $sessionId = $reponse->json('data.id');

        $this->travel(7)->minutes();

        $this->postJson("/api/v1/tele/sessions/{$sessionId}/terminer", ['cloturer' => false], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.est_terminee', true)
            ->assertJsonPath('data.duree_secondes', 420)
            ->assertJsonPath('data.duree_minutes', 7)
            ->assertJsonPath('data.cloture_rendez_vous', false)
            ->assertJsonMissingPath('facture');

        // Le rendez-vous reste en cours, aucune facture — et l'appel
        // peut reprendre : nouvelle session sur le même RDV.
        $this->assertSame(StatutRendezVous::Cours, $rendezVous->refresh()->status);
        $this->assertDatabaseCount('factures', 0);

        $this->postJson('/api/v1/tele/sessions', ['rendez_vous_id' => $rendezVous->id], $this->jeton())
            ->assertCreated();
    }

    public function test_terminer_clot_le_rdv_lie_et_facture_l_acte_de_teleconsultation(): void
    {
        $rendezVous = $this->rendezVousTele();
        $reponse = $this->postJson('/api/v1/tele/sessions', ['rendez_vous_id' => $rendezVous->id], $this->jeton());
        $sessionId = $reponse->json('data.id');

        $this->travel(3)->minutes();

        $this->postJson("/api/v1/tele/sessions/{$sessionId}/terminer", ['cloturer' => true], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.cloture_rendez_vous', true)
            ->assertJsonPath('facture.statut', 'due');

        $this->assertSame(StatutRendezVous::Fini, $rendezVous->refresh()->status);

        $facture = Facture::withoutGlobalScopes()->sole();
        $this->assertSame($rendezVous->id, $facture->rendez_vous_id);
        // L'acte facturé est la prestation du type `tele`.
        $this->assertSame('Téléconsultation', $facture->lignes()->sole()->libelle);
    }

    public function test_terminer_un_appel_libre_ne_facture_rien(): void
    {
        $session = SessionTeleconsultation::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
        ]);

        $this->postJson("/api/v1/tele/sessions/{$session->id}/terminer", ['cloturer' => true], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.cloture_rendez_vous', false)
            ->assertJsonMissingPath('facture');

        $this->assertDatabaseCount('factures', 0);
    }

    public function test_une_session_terminee_ne_se_termine_pas_deux_fois(): void
    {
        $session = SessionTeleconsultation::factory()->terminee()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
        ]);

        $this->postJson("/api/v1/tele/sessions/{$session->id}/terminer", ['cloturer' => true], $this->jeton())
            ->assertUnprocessable();
    }

    public function test_aucune_route_de_suppression_de_session(): void
    {
        $session = SessionTeleconsultation::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
        ]);

        $this->deleteJson("/api/v1/tele/sessions/{$session->id}", [], $this->jeton())
            ->assertStatus(405);
    }
}
