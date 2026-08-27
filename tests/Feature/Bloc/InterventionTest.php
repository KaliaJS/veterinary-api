<?php

namespace Tests\Feature\Bloc;

use App\Enums\StatutIntervention;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\Intervention;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\RendezVousAudit;
use App\Models\Salle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Dossier opératoire (docs/data-model.md §11) : cycle prevu → prep →
 * cours → reveil → fini un cran à la fois, checklist de sécurité
 * bloquante avant l'incision, consentement coché par sa seule
 * signature (document archivé au dossier), note opératoire. Le RDV
 * `chir` lié est la projection planning de l'intervention : elle
 * pousse horaires et clôture vers lui — et la clôture facture l'acte
 * comme tout RDV fini.
 */
class InterventionTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    private Salle $salle;

    private Employe $chirurgien;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['employe' => $this->chirurgien, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();

        $this->salle = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id]);
    }

    /** @return array<string, string> */
    private function jeton(): array
    {
        return $this->enTetes($this->jeton);
    }

    private function intervention(array $attributs = []): Intervention
    {
        return Intervention::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'salle_id' => $this->salle->id,
            'salle_nom' => $this->salle->nom,
            'chirurgien_id' => $this->chirurgien->id,
            ...$attributs,
        ]);
    }

    private function rendezVousChir(): RendezVous
    {
        return RendezVous::factory()->pourPatient($this->patient)->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Chirurgie,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(10, 0),
        ]);
    }

    public function test_la_programmation_cree_le_dossier_avec_checklist_et_protocole_par_defaut(): void
    {
        $reponse = $this->postJson('/api/v1/bloc/interventions', [
            'patient_id' => $this->patient->id,
            'procedure' => 'Ovariohystérectomie de convenance',
            'prevu_le' => now()->setTime(14, 30)->toISOString(),
            'duree_minutes' => 75,
            'salle_id' => $this->salle->id,
            'chirurgien_id' => $this->chirurgien->id,
        ], $this->jeton())->assertCreated();

        $reponse->assertJsonPath('data.statut', 'prevu')
            ->assertJsonPath('data.salle_nom', $this->salle->nom)
            ->assertJsonCount(6, 'data.checklist')
            ->assertJsonCount(4, 'data.protocole_anesthesique')
            ->assertJsonPath('data.checklist.0.code', 'jeun')
            ->assertJsonPath('data.checklist.0.est_coche', false);
    }

    public function test_le_cycle_avance_un_cran_a_la_fois_et_se_journalise(): void
    {
        $intervention = $this->intervention();

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'prep')
            ->assertJsonCount(1, 'data.transitions')
            ->assertJsonPath('data.transitions.0.statut', 'prep');

        $this->assertDatabaseCount('intervention_transitions', 1);
    }

    public function test_l_incision_est_bloquee_tant_que_la_checklist_n_est_pas_complete(): void
    {
        $intervention = $this->intervention(['statut' => StatutIntervention::Preparation]);

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('checklist');

        // Cocher tout sauf le consentement ne suffit pas.
        foreach (['jeun', 'premed', 'iv', 'atb', 'mat'] as $code) {
            $this->putJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/{$code}", ['est_coche' => true], $this->jeton())
                ->assertOk();
        }

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertUnprocessable();

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", [], $this->jeton())->assertOk();

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'cours');

        $this->assertNotNull($intervention->refresh()->incision_a);
    }

    public function test_le_consentement_ne_se_coche_que_par_sa_signature(): void
    {
        $intervention = $this->intervention();

        $this->putJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/consent", ['est_coche' => true], $this->jeton())
            ->assertUnprocessable();

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.checklist.1.est_coche', true);

        $intervention->refresh();
        $this->assertNotNull($intervention->consentement_signe_le);

        // Archivé au dossier du patient.
        $document = Document::find($intervention->consentement_document_id);
        $this->assertSame($this->patient->id, $document->patient_id);
        $this->assertSame('consentement', $document->type);

        // Une seule signature.
        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", [], $this->jeton())
            ->assertUnprocessable();
    }

    public function test_un_point_ajoute_bloque_l_incision_et_peut_se_retirer(): void
    {
        $intervention = Intervention::factory()->checklistComplete()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'salle_id' => $this->salle->id,
            'salle_nom' => $this->salle->nom,
            'chirurgien_id' => $this->chirurgien->id,
            'statut' => StatutIntervention::Preparation,
        ]);

        $reponse = $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/checklist", [
            'libelle' => 'Groupe sanguin vérifié — transfusion possible',
        ], $this->jeton())->assertOk();

        $code = collect($reponse->json('data.checklist'))->firstWhere('est_ajoute', true)['code'];

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertUnprocessable();

        $this->deleteJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/{$code}", [], $this->jeton())
            ->assertOk()
            ->assertJsonCount(6, 'data.checklist');

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())->assertOk();
    }

    public function test_un_point_de_base_ne_se_retire_pas(): void
    {
        $intervention = $this->intervention();

        $this->deleteJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/jeun", [], $this->jeton())
            ->assertUnprocessable();
    }

    public function test_la_reprogrammation_change_salle_et_denormalise_son_nom(): void
    {
        $intervention = $this->intervention();
        $autreSalle = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id, 'nom' => 'Bloc 2']);

        $this->patchJson("/api/v1/bloc/interventions/{$intervention->id}", [
            'salle_id' => $autreSalle->id,
            'duree_minutes' => 90,
        ], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.salle_nom', 'Bloc 2')
            ->assertJsonPath('data.duree_minutes', 90);
    }

    public function test_une_intervention_terminee_n_accepte_plus_que_la_note_operatoire(): void
    {
        $intervention = $this->intervention(['statut' => StatutIntervention::Fini]);

        $this->patchJson("/api/v1/bloc/interventions/{$intervention->id}", [
            'duree_minutes' => 90,
        ], $this->jeton())->assertUnprocessable();

        $this->patchJson("/api/v1/bloc/interventions/{$intervention->id}", [
            'note_operatoire' => 'Exérèse complète, marges saines. Suites simples.',
        ], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.note_operatoire', 'Exérèse complète, marges saines. Suites simples.');

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertUnprocessable();
    }

    public function test_la_programmation_avec_rdv_lie_aligne_la_projection_planning(): void
    {
        $rendezVous = $this->rendezVousChir();
        $prevuLe = now()->setTime(14, 30);

        $this->postJson('/api/v1/bloc/interventions', [
            'patient_id' => $this->patient->id,
            'procedure' => 'Cystotomie',
            'prevu_le' => $prevuLe->toISOString(),
            'duree_minutes' => 60,
            'salle_id' => $this->salle->id,
            'chirurgien_id' => $this->chirurgien->id,
            'rendez_vous_id' => $rendezVous->id,
        ], $this->jeton())->assertCreated();

        $rendezVous->refresh();
        $this->assertTrue($rendezVous->starts_at->equalTo($prevuLe));
        $this->assertTrue($rendezVous->ends_at->equalTo($prevuLe->copy()->addMinutes(60)));
        $this->assertSame($this->salle->id, $rendezVous->salle_id);

        // Le geste est audité comme n'importe quelle mutation planning.
        $this->assertTrue(
            RendezVousAudit::where('rendez_vous_id', $rendezVous->id)->where('champ', 'startsAt')->exists(),
        );
    }

    public function test_la_cloture_passe_le_rdv_lie_en_fini_et_facture_l_acte(): void
    {
        $rendezVous = $this->rendezVousChir();
        $intervention = $this->intervention([
            'statut' => StatutIntervention::Reveil,
            'rendez_vous_id' => $rendezVous->id,
        ]);

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'fini')
            ->assertJsonPath('facture.statut', 'due');

        $this->assertSame(StatutRendezVous::Fini, $rendezVous->refresh()->status);

        $facture = Facture::withoutGlobalScopes()->sole();
        $this->assertSame($rendezVous->id, $facture->rendez_vous_id);
        // L'acte facturé est la prestation du type `chir`.
        $this->assertSame('Chirurgie — forfait bloc', $facture->lignes()->sole()->libelle);
    }

    public function test_la_cloture_sans_rdv_lie_ne_facture_rien(): void
    {
        $intervention = $this->intervention(['statut' => StatutIntervention::Reveil]);

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'fini')
            ->assertJsonMissingPath('facture');

        $this->assertDatabaseCount('factures', 0);
    }

    public function test_le_demarrage_reflete_le_statut_sur_le_rdv_lie(): void
    {
        $rendezVous = $this->rendezVousChir();
        $intervention = Intervention::factory()->checklistComplete()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'salle_id' => $this->salle->id,
            'salle_nom' => $this->salle->nom,
            'chirurgien_id' => $this->chirurgien->id,
            'statut' => StatutIntervention::Preparation,
            'rendez_vous_id' => $rendezVous->id,
        ]);

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'cours');

        $this->assertSame(StatutRendezVous::Cours, $rendezVous->refresh()->status);
    }

    public function test_un_rdv_annule_ou_d_un_autre_type_ne_se_lie_pas(): void
    {
        $annule = RendezVous::factory()->annule()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Chirurgie,
        ]);
        $consultation = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
        ]);

        foreach ([$annule, $consultation] as $rendezVous) {
            $this->postJson('/api/v1/bloc/interventions', [
                'patient_id' => $this->patient->id,
                'procedure' => 'Cystotomie',
                'prevu_le' => now()->toISOString(),
                'duree_minutes' => 60,
                'salle_id' => $this->salle->id,
                'chirurgien_id' => $this->chirurgien->id,
                'rendez_vous_id' => $rendezVous->id,
            ], $this->jeton())
                ->assertUnprocessable()
                ->assertJsonValidationErrors('rendez_vous_id');
        }
    }

    public function test_aucune_route_de_suppression_d_intervention(): void
    {
        $intervention = $this->intervention();

        $this->deleteJson("/api/v1/bloc/interventions/{$intervention->id}", [], $this->jeton())
            ->assertStatus(405);
    }
}
