<?php

namespace Tests\Feature\Imagerie;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\Facture;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\RendezVousAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Worklist d'imagerie (docs/data-model.md §10) : examens par modalité,
 * flux DICOM idempotent, compte rendu signé qui fige l'examen. Même
 * patron que le bloc : l'examen est le dossier maître, le RDV
 * `imagerie` lié n'est que sa projection planning — l'examen pousse
 * horaires et clôture, la signature facture l'acte comme tout RDV
 * fini.
 */
class ExamenImagerieTest extends TestCase
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

    private function examen(array $attributs = []): ExamenImagerie
    {
        return ExamenImagerie::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'prescripteur_id' => $this->employe->id,
            ...$attributs,
        ]);
    }

    private function rendezVousImagerie(): RendezVous
    {
        return RendezVous::factory()->pourPatient($this->patient)->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Imagerie,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(9, 30),
        ]);
    }

    public function test_l_enregistrement_cree_l_examen_a_interpreter_avec_l_operateur_du_jeton(): void
    {
        $this->postJson('/api/v1/imagerie/examens', [
            'patient_id' => $this->patient->id,
            'modalite' => 'US',
            'libelle' => 'Échographie abdominale',
            'incidence' => 'Sonde micro-convexe 8 MHz',
            'realise_le' => now()->toISOString(),
        ], $this->jeton())
            ->assertCreated()
            ->assertJsonPath('data.modalite', 'US')
            ->assertJsonPath('data.est_signe', false)
            ->assertJsonPath('data.prescripteur.id', $this->employe->id)
            ->assertJsonPath('data.duree_minutes', 30);
    }

    public function test_l_enregistrement_avec_rdv_lie_aligne_la_projection_planning(): void
    {
        $rendezVous = $this->rendezVousImagerie();
        $realiseLe = now()->setTime(14, 0);

        $this->postJson('/api/v1/imagerie/examens', [
            'patient_id' => $this->patient->id,
            'modalite' => 'DX',
            'libelle' => 'Radiographie thorax',
            'realise_le' => $realiseLe->toISOString(),
            'duree_minutes' => 45,
            'rendez_vous_id' => $rendezVous->id,
        ], $this->jeton())->assertCreated();

        $rendezVous->refresh();
        $this->assertTrue($rendezVous->starts_at->equalTo($realiseLe));
        $this->assertTrue($rendezVous->ends_at->equalTo($realiseLe->copy()->addMinutes(45)));

        // Le geste est audité comme n'importe quelle mutation planning.
        $this->assertTrue(
            RendezVousAudit::where('rendez_vous_id', $rendezVous->id)->where('champ', 'startsAt')->exists(),
        );
    }

    public function test_la_reprogrammation_pousse_la_projection_sans_retour_du_planning(): void
    {
        $rendezVous = $this->rendezVousImagerie();
        $examen = $this->examen(['rendez_vous_id' => $rendezVous->id]);
        $nouveau = now()->setTime(16, 15);

        $this->patchJson("/api/v1/imagerie/examens/{$examen->id}", [
            'realise_le' => $nouveau->toISOString(),
        ], $this->jeton())->assertOk();

        $this->assertTrue($rendezVous->refresh()->starts_at->equalTo($nouveau));
    }

    public function test_un_rdv_annule_d_un_autre_type_ou_deja_lie_ne_se_lie_pas(): void
    {
        $annule = RendezVous::factory()->annule()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Imagerie,
        ]);
        $consultation = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
        ]);
        $dejaLie = $this->rendezVousImagerie();
        $this->examen(['rendez_vous_id' => $dejaLie->id]);

        foreach ([$annule, $consultation, $dejaLie] as $rendezVous) {
            $this->postJson('/api/v1/imagerie/examens', [
                'patient_id' => $this->patient->id,
                'modalite' => 'DX',
                'libelle' => 'Radiographie thorax',
                'realise_le' => now()->toISOString(),
                'rendez_vous_id' => $rendezVous->id,
            ], $this->jeton())
                ->assertUnprocessable()
                ->assertJsonValidationErrors('rendez_vous_id');
        }
    }

    public function test_la_signature_exige_des_constatations(): void
    {
        $examen = $this->examen();

        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/compte-rendu", [
            'conclusion' => 'RAS',
        ], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('constatations');
    }

    public function test_la_signature_fige_l_examen_et_ne_se_rejoue_pas(): void
    {
        $examen = $this->examen();

        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/compte-rendu", [
            'constatations' => 'Champs pulmonaires clairs, silhouette cardiaque dans les limites.',
            'conclusion' => 'Examen dans les limites de la normale.',
        ], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.est_signe', true)
            ->assertJsonPath('data.signe_par.id', $this->employe->id);

        $this->assertNotNull($examen->refresh()->signe_le);

        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/compte-rendu", [
            'constatations' => 'Autre lecture.',
        ], $this->jeton())->assertUnprocessable();

        $this->patchJson("/api/v1/imagerie/examens/{$examen->id}", [
            'libelle' => 'Autre libellé',
        ], $this->jeton())->assertUnprocessable();
    }

    public function test_la_signature_clot_le_rdv_lie_et_facture_l_acte_d_imagerie(): void
    {
        $rendezVous = $this->rendezVousImagerie();
        $examen = $this->examen(['rendez_vous_id' => $rendezVous->id]);

        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/compte-rendu", [
            'constatations' => 'Opacité alvéolaire en lobe moyen droit.',
            'conclusion' => 'Image compatible avec une bronchopneumonie.',
        ], $this->jeton())
            ->assertOk()
            ->assertJsonPath('facture.statut', 'due');

        $this->assertSame(StatutRendezVous::Fini, $rendezVous->refresh()->status);

        $facture = Facture::withoutGlobalScopes()->sole();
        $this->assertSame($rendezVous->id, $facture->rendez_vous_id);
        // L'acte facturé est la prestation du type `imagerie`.
        $this->assertSame('Radiographie — 2 clichés', $facture->lignes()->sole()->libelle);
    }

    public function test_la_signature_sans_rdv_lie_ne_facture_rien(): void
    {
        $examen = $this->examen();

        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/compte-rendu", [
            'constatations' => 'Sans particularité.',
        ], $this->jeton())
            ->assertOk()
            ->assertJsonMissingPath('facture');

        $this->assertDatabaseCount('factures', 0);
    }

    public function test_le_flux_dicom_est_idempotent_par_sop_instance_uid(): void
    {
        $examen = $this->examen();

        $cliche = [
            'sop_instance_uid' => '1.2.840.113619.2.55.3.604688.1',
            'study_instance_uid' => '1.2.840.113619.2.55.3.604688',
            'nom_fichier' => 'thorax_profil.dcm',
            'metadonnees' => ['kvp' => 68, 'mas' => 4.5],
        ];

        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/cliches", $cliche, $this->jeton())
            ->assertOk()
            ->assertJsonCount(1, 'data.cliches')
            ->assertJsonPath('data.cliches.0.metadonnees.kvp', 68);

        // La modalité ré-émet : pas de doublon.
        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/cliches", $cliche, $this->jeton())
            ->assertOk()
            ->assertJsonCount(1, 'data.cliches');

        $this->assertDatabaseCount('cliches_dicom', 1);
    }

    public function test_la_worklist_filtre_par_modalite_et_compte_rendu(): void
    {
        $this->examen(['libelle' => 'Radiographie thorax']);
        ExamenImagerie::factory()->signe()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'modalite' => 'US',
            'libelle' => 'Échographie abdominale',
        ]);

        $entetes = $this->jeton();

        $this->getJson('/api/v1/imagerie/examens?statut=todo', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.libelle', 'Radiographie thorax');

        $this->getJson('/api/v1/imagerie/examens?modalite=US&statut=fait', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.libelle', 'Échographie abdominale');
    }

    public function test_les_trames_de_compte_rendu_sont_servies_par_modalite(): void
    {
        $this->getJson('/api/v1/imagerie/trames', $this->jeton())
            ->assertOk()
            ->assertJsonStructure(['data' => ['DX' => ['constatations', 'conclusion'], 'US' => [], 'ES' => []]]);
    }

    public function test_aucune_route_de_suppression_d_examen(): void
    {
        $examen = $this->examen();

        $this->deleteJson("/api/v1/imagerie/examens/{$examen->id}", [], $this->jeton())
            ->assertStatus(405);
    }
}
