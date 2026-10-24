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
use App\Services\ProjectionPlanningBloc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_les_ressources_du_bloc_sont_les_salles_de_bloc_et_les_veterinaires_de_la_clinique(): void
    {
        Salle::factory()->create(['clinique_id' => $this->clinique->id, 'type' => 'consultation', 'nom' => 'Consultation 1']);
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        Salle::factory()->bloc()->create(['clinique_id' => $soeur->id]);
        Employe::factory()->dans($this->clinique)->create(['role_libelle' => 'ASV · Bloc', 'nom' => 'Lena Berger']);
        Employe::factory()->dans($this->clinique)->create(['role_libelle' => 'Vétérinaire', 'nom' => 'Dr Partie', 'acces_suspendu' => true]);
        Employe::factory()->dans($soeur)->create(['role_libelle' => 'Vétérinaire', 'nom' => 'Dr Ailleurs']);

        $reponse = $this->getJson('/api/v1/bloc/ressources', $this->jeton())->assertOk();

        $this->assertSame([$this->salle->id], array_column($reponse->json('data.salles'), 'id'));
        $this->assertSame([$this->chirurgien->id], array_column($reponse->json('data.chirurgiens'), 'id'));
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

    /** Un rendez-vous ne porte qu'une intervention, et seulement pour son patient : refusé, pas une erreur 500. */
    public function test_le_rendez_vous_lie_est_libre_et_du_meme_patient(): void
    {
        $rendezVous = $this->rendezVousChir();
        $donnees = fn (int $patient) => [
            'patient_id' => $patient,
            'procedure' => 'Détartrage',
            'prevu_le' => now()->setTime(9, 0)->toISOString(),
            'duree_minutes' => 60,
            'salle_id' => $this->salle->id,
            'chirurgien_id' => $this->chirurgien->id,
            'rendez_vous_id' => $rendezVous->id,
        ];
        $autre = Patient::factory()->for($this->patient->client)->create();

        $this->postJson('/api/v1/bloc/interventions', $donnees($autre->id), $this->jeton())
            ->assertUnprocessable()->assertJsonValidationErrors('rendez_vous_id');
        $this->postJson('/api/v1/bloc/interventions', $donnees($this->patient->id), $this->jeton())->assertCreated();
        $this->postJson('/api/v1/bloc/interventions', $donnees($this->patient->id), $this->jeton())
            ->assertUnprocessable()->assertJsonValidationErrors(['rendez_vous_id' => 'Ce rendez-vous a déjà son intervention.']);
    }

    /** En modification non plus : ni le rendez-vous d'un autre animal, ni celui qui a déjà son intervention, ni changer d'animal en le gardant. */
    public function test_la_modification_ne_detourne_pas_le_rendez_vous_d_un_autre_patient(): void
    {
        $rendezVous = $this->rendezVousChir();
        $liee = $this->intervention(['rendez_vous_id' => $rendezVous->id]);
        $libre = $this->intervention();
        $autreClient = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $autre = Patient::factory()->for($autreClient)->create();
        $sienne = $this->intervention(['patient_id' => $autre->id]);

        // Le rendez-vous a déjà son intervention
        $this->patchJson("/api/v1/bloc/interventions/{$libre->id}", ['rendez_vous_id' => $rendezVous->id], $this->jeton())
            ->assertUnprocessable()->assertJsonValidationErrors(['rendez_vous_id' => 'Ce rendez-vous a déjà son intervention.']);
        // Le rendez-vous d'un autre animal
        $rendezVousAutre = RendezVous::factory()->pourPatient($autre)->create([
            'clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Chirurgie,
            'starts_at' => now()->setTime(11, 0), 'ends_at' => now()->setTime(12, 0),
        ]);
        $this->patchJson("/api/v1/bloc/interventions/{$libre->id}", ['rendez_vous_id' => $rendezVousAutre->id], $this->jeton())
            ->assertUnprocessable()->assertJsonValidationErrors('rendez_vous_id');
        // Changer d'animal en gardant le rendez-vous : refusé, le rendez-vous reste à son animal
        $this->patchJson("/api/v1/bloc/interventions/{$liee->id}", ['patient_id' => $autre->id], $this->jeton())
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        $this->assertSame($this->patient->id, $rendezVous->refresh()->patient_id);
        $this->assertSame($this->patient->client_id, $rendezVous->client_id);

        // Changer d'animal en détachant le rendez-vous, ou garder le sien : accepté
        $this->patchJson("/api/v1/bloc/interventions/{$liee->id}", ['patient_id' => $autre->id, 'rendez_vous_id' => null], $this->jeton())->assertOk();
        $this->patchJson("/api/v1/bloc/interventions/{$sienne->id}", ['rendez_vous_id' => $rendezVousAutre->id, 'duree_minutes' => 45], $this->jeton())->assertOk();
        $this->assertSame($this->patient->id, $rendezVous->refresh()->patient_id);
    }

    /** Un lien plus ancien et discordant : reprogrammer l'intervention ne fait jamais changer le rendez-vous d'animal (24.10.2026). */
    public function test_aligner_ne_change_jamais_le_patient_du_rendez_vous(): void
    {
        $rendezVous = $this->rendezVousChir();
        $autre = Patient::factory()->for($this->patient->client)->create();
        $intervention = $this->intervention(['patient_id' => $autre->id, 'rendez_vous_id' => $rendezVous->id]);

        app(ProjectionPlanningBloc::class)->aligner($intervention->refresh(), null);

        $this->assertSame($this->patient->id, $rendezVous->refresh()->patient_id);
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['checklist' => 'Checklist de sécurité incomplète.']);

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", $this->signature(), $this->jeton())->assertOk();

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'cours');

        $this->assertNotNull($intervention->refresh()->incision_a);
    }

    public function test_le_consentement_ne_se_coche_que_par_sa_signature(): void
    {
        Storage::fake('prive');
        $intervention = $this->intervention();

        // Sans signature tracée, ou avec autre chose qu'une image : refusé
        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", ['signataire' => 'Mme Pineau'], $this->jeton())
            ->assertUnprocessable()->assertJsonValidationErrors('signature');
        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", ['signataire' => 'Mme Pineau', 'signature' => 'data:image/png;base64,'.base64_encode('<svg/>')], $this->jeton())
            ->assertUnprocessable()->assertJsonValidationErrors('signature');

        $this->putJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/consent", ['est_coche' => true], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['est_coche' => 'Le consentement ne se coche que par sa signature.']);

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", $this->signature(), $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.checklist.1.est_coche', true);

        $intervention->refresh();
        $this->assertNotNull($intervention->consentement_signe_le);

        // Archivé au dossier du patient.
        $document = Document::find($intervention->consentement_document_id);
        $this->assertSame($this->patient->id, $document->patient_id);
        $this->assertSame('consentement', $document->type);

        // La preuve : l'image de la signature, qui a signé, qui l'a recueillie
        $this->assertSame('Mme Pineau', $document->signataire);
        $this->assertStringStartsWith("\x89PNG", Storage::disk('prive')->get($document->chemin));
        $this->assertNotNull($intervention->consentement_recueilli_par_id);

        // Il se télécharge depuis le dossier, par sa route privée
        $this->getJson("/api/v1/patients/{$this->patient->id}/documents", $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.0.a_fichier', true);
        $fichier = $this->get("/api/v1/patients/{$this->patient->id}/documents/{$document->id}/fichier", $this->jeton())->assertOk();
        $this->assertStringStartsWith("\x89PNG", $fichier->streamedContent());

        // Pas par le dossier d'un autre patient, ni sans fichier
        $autre = Patient::factory()->for($this->patient->client)->create();
        $this->getJson("/api/v1/patients/{$autre->id}/documents/{$document->id}/fichier", $this->jeton())->assertNotFound();
        $sansFichier = $this->patient->documents()->create(['nom' => 'Ancien courrier', 'etabli_le' => aujourdhui()]);
        $this->getJson("/api/v1/patients/{$this->patient->id}/documents/{$sansFichier->id}/fichier", $this->jeton())->assertNotFound();

        // Une seule signature.
        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", $this->signature(), $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consentement' => 'Le consentement est déjà signé.']);
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['checklist' => 'Checklist de sécurité incomplète.']);

        $this->deleteJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/{$code}", [], $this->jeton())
            ->assertOk()
            ->assertJsonCount(6, 'data.checklist');

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())->assertOk();
    }

    public function test_un_point_de_base_ne_se_retire_pas(): void
    {
        $intervention = $this->intervention();

        $this->deleteJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/jeun", [], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Les six points de base ne se retirent pas.']);
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
        ], $this->jeton())->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Une intervention terminée n\'accepte plus que la note opératoire.']);

        $this->patchJson("/api/v1/bloc/interventions/{$intervention->id}", [
            'note_operatoire' => 'Exérèse complète, marges saines. Suites simples.',
        ], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.note_operatoire', 'Exérèse complète, marges saines. Suites simples.');

        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statut' => 'Une intervention terminée ne change plus de statut.']);
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
        $this->assertSame('Chirurgie : forfait bloc', $facture->lignes()->sole()->libelle);
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

    /** Une signature tracée : une image PNG, et le nom de qui consent */
    private function signature(): array
    {
        $image = imagecreatetruecolor(240, 90);
        imageline($image, 10, 60, 230, 30, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);

        return ['signataire' => 'Mme Pineau', 'signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean())];
    }
}
