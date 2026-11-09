<?php

namespace Tests\Feature\Bloc;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutIntervention;
use App\Events\EnregistrementMisAJour;
use App\Events\InterventionUpdated;
use App\Jobs\WriteSurgeryReport;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\EnregistrementConsultation;
use App\Models\Intervention;
use App\Models\PartieEnregistrement;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Salle;
use App\Models\SurgeryReport;
use App\Services\Bloc\SurgeryReportDrafts;
use App\Services\Bloc\SurgeryReportSources;
use App\Services\Bloc\SurgeryReportWriter;
use App\Services\Bloc\SurgeryReportWriterClaude;
use App\Services\CompteurMensuel;
use App\Services\Transcription\Transcripteur;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\SurgeryReportWriterFake;
use Tests\Doubles\TranscripteurFactice;
use Tests\TestCase;

/**
 * IA pour le rapport de bloc (C, choix de Jim, 09.11.2026). Le brouillon
 * part de l'écran du bloc (intervention, déroulé, protocole, checklist),
 * des notes tapées et, si le chirurgien a dicté, de la dictée. Chaque
 * phrase cite ses sources ; une phrase sans source réelle est retirée ; une
 * rubrique vide reste à compléter. Rien n'est au rapport avant la
 * validation (droit de signer les comptes rendus), qui le fige ; des
 * compléments datés s'y ajoutent ensuite. Budget et plafond de l'IA.
 */
class SurgeryReportTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Intervention $intervention;

    private SurgeryReportWriterFake $writer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->app->instance(SurgeryReportWriter::class, $this->writer = new SurgeryReportWriterFake);
        $this->app->instance(Transcripteur::class, new TranscripteurFactice);

        $this->clinique = Clinique::factory()->create();
        $organisation = $this->clinique->societe->organisation;
        $organisation->forceFill(['stripe_client_id' => 'cus_1', 'stripe_moyen_paiement_id' => 'pm_1', 'statut_abonnement' => 'actif'])->save();
        ['employe' => $chirurgien, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anna Meyer']);
        $patient = Patient::factory()->for(Client::factory()->create(['organisation_id' => $organisation->id]))->create(['nom' => 'Filou']);
        $salle = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id, 'nom' => 'Salle 1']);

        $this->intervention = Intervention::query()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $patient->id,
            'salle_id' => $salle->id,
            'salle_nom' => 'Salle 1',
            'chirurgien_id' => $chirurgien->id,
            'procedure' => 'Orchiectomie',
            'prevu_le' => now()->subHours(2),
            'duree_minutes' => 45,
            'statut' => StatutIntervention::Fini,
            'protocole_anesthesique' => ['Prémédication : médétomidine 5 µg/kg IM', 'Induction : propofol 2 mg/kg IV'],
            'note_operatoire' => "abord préscrotal, technique fermée\n\ncollerette 10 j",
            'incision_a' => now()->subHours(2)->addMinutes(10),
        ]);
        $this->intervention->checklist()->create(['code' => 'identite', 'libelle' => 'Identité vérifiée', 'est_coche' => true]);
        app(ContexteClinique::class)->oublier();
    }

    private function uri(string $suffix = ''): string
    {
        return "/api/v1/bloc/interventions/{$this->intervention->id}/report".$suffix;
    }

    public function test_le_brouillon_part_de_l_ecran_du_bloc_et_des_notes(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();

        $sources = collect($this->writer->calls[0]);
        $this->assertSame(['E1', 'E2', 'E3', 'E4', 'E5', 'N1', 'N2'], $sources->pluck('id')->all());
        $this->assertStringContainsString('Orchiectomie', $sources->firstWhere('id', 'E1')['text']);
        $this->assertStringContainsString('Dre Anna Meyer', $sources->firstWhere('id', 'E1')['text']);
        $this->assertSame('Déroulé', $sources->firstWhere('id', 'E2')['label']);
        $this->assertSame('Induction : propofol 2 mg/kg IV', $sources->firstWhere('id', 'E4')['text']);
        $this->assertStringContainsString('Identité vérifiée', $sources->firstWhere('id', 'E5')['text']);
        // Les notes, une source par ligne non vide
        $this->assertSame(['abord préscrotal, technique fermée', 'collerette 10 j'], $sources->where('kind', 'note')->pluck('text')->values()->all());

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_validated', false)
            ->assertJsonPath('data.draft.sections.intervention.0.sources', ['E1'])
            ->assertJsonPath('data.draft.sources.5.text', 'abord préscrotal, technique fermée');
    }

    public function test_sans_source_reelle_une_phrase_est_retiree_et_les_tirets_partent(): void
    {
        $this->writer->result['sections']['material'] = [
            ['text' => 'Ligature PDS 2-0.', 'sources' => ['N9']],
            ['text' => 'Suture intradermique.', 'sources' => []],
        ];
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.draft.sections.material', [])
            ->assertJsonPath('data.draft.sections.technique.0.text', 'Abord préscrotal, technique fermée.')
            // Une rubrique vide reste à compléter : vide dans le texte à relire
            ->assertJsonPath('data.sections.complications', '')
            ->assertJsonPath('data.sections.aftercare', 'Collerette pendant 10 jours.');
    }

    public function test_un_tiret_entre_deux_nombres_devient_un_intervalle(): void
    {
        $this->writer->result['sections']['anaesthesia'] = [['text' => 'Maintien sous isoflurane 1,5–2 % — O2 100 %.', 'sources' => ['E3']]];
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.draft.sections.anaesthesia.0.text', 'Maintien sous isoflurane 1,5 à 2 %, O2 100 %.');
    }

    public function test_un_doute_ne_reste_que_si_son_passage_est_dans_la_rubrique(): void
    {
        $this->writer->result['doubts'] = [
            ['section' => 'aftercare', 'passage' => 'Collerette', 'heard' => 'colle rette', 'reason' => 'Mot reconnu de travers.'],
            ['section' => 'aftercare', 'passage' => 'Monocryl', 'heard' => 'mono cryl', 'reason' => 'Absent du texte.'],
            ['section' => 'material', 'passage' => '', 'heard' => '', 'reason' => 'Le fil de la peau n’est pas dit.'],
        ];
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonCount(2, 'data.draft.doubts')
            ->assertJsonPath('data.draft.doubts.0.passage', 'Collerette')
            ->assertJsonPath('data.draft.doubts.1.reason', 'Le fil de la peau n’est pas dit.');
    }

    public function test_la_dictee_du_chirurgien_nourrit_le_brouillon(): void
    {
        $id = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.intervention_id', $this->intervention->id)
            ->json('data.id');
        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', TranscripteurFactice::ENTETE.'AA'),
        ], [...$this->enTetes($this->jeton), 'Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertOk();

        // Transcrite : la dictée est prête (pas de brouillon SOAP), le brouillon du rapport s'écrit
        $this->assertSame(StatutEnregistrement::Pret, EnregistrementConsultation::withoutGlobalScopes()->find($id)->statut);
        $this->assertCount(1, $this->writer->calls);
        $dictation = collect($this->writer->calls[0])->where('kind', 'dictation')->values();
        $this->assertSame(['D1', 'D2'], $dictation->pluck('id')->all());
        $this->assertSame('0:12', $dictation[1]['label']);
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.status', 'draft');
    }

    public function test_sans_dictee_le_brouillon_part_des_notes_et_de_l_ecran(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->assertSame([], collect($this->writer->calls[0])->where('kind', 'dictation')->all());
    }

    public function test_le_veterinaire_corrige_chaque_rubrique_avant_de_valider(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['complications' => 'Aucune.', 'implants' => '']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.sections.complications', 'Aucune.')
            ->assertJsonPath('data.sections.aftercare', 'Collerette pendant 10 jours.');
        $this->patchJson($this->uri(), ['sections' => ['conclusion' => 'x']], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_valider_demande_le_droit_de_signer_et_fige_le_rapport(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));

        // Un rôle avec le bloc mais sans le droit de signer
        ['employe' => $sansDroit, 'jeton' => $assistant] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScopes()->create([
            'organisation_id' => $sansDroit->organisation_id,
            'nom' => 'Aide de bloc',
            'domaines' => array_values(array_filter(DomainePermission::cases(), fn (DomainePermission $d) => $d !== DomainePermission::SignerComptesRendus)),
        ]);
        $sansDroit->forceFill(['role_id' => $role->id])->save();
        $this->postJson($this->uri('/validate'), [], $this->enTetes($assistant))->assertForbidden();
        app(ContexteClinique::class)->oublier();

        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.is_validated', true)
            ->assertJsonPath('data.validated_by_name', 'Dre Anna Meyer');

        $this->patchJson($this->uri(), ['sections' => ['complications' => 'Plus tard.']], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertSame('', SurgeryReport::first()->sections['complications']);
    }

    public function test_un_rapport_vide_ne_se_valide_pas(): void
    {
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_valide_des_complements_dates_et_signes_s_y_ajoutent(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/complements'), ['text' => 'Avant validation.'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton));

        $this->postJson($this->uri('/complements'), ['text' => 'Contrôle à J3 avancé.'], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.complements.0.text', 'Contrôle à J3 avancé.')
            ->assertJsonPath('data.complements.0.by_name', 'Dre Anna Meyer')
            ->assertJsonPath('data.complements.0.at', fn ($at) => is_string($at) && $at !== '');
    }

    public function test_rien_n_est_au_rapport_avant_la_validation(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.is_validated', false)
            ->assertJsonPath('data.validated_at', null);
        $this->getJson("/api/v1/bloc/interventions/{$this->intervention->id}", $this->enTetes($this->jeton))
            ->assertJsonPath('data.report_status', 'draft');
    }

    public function test_a_terminer_compte_le_rapport_tant_qu_il_n_est_pas_valide(): void
    {
        $kinds = fn () => collect($this->getJson('/api/v1/unfinished-work', $this->enTetes($this->jeton))->json('data.groups'))->pluck('kind')->all();

        // Une note opératoire ne suffit plus : le rapport doit être validé
        $this->assertContains('surgery_report', $kinds());
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->assertContains('surgery_report', $kinds());
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton));
        $this->assertNotContains('surgery_report', $kinds());
    }

    public function test_les_redactions_restent_dans_le_budget_et_le_plafond(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        }
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->assertCount(3, $this->writer->calls);

        SurgeryReport::query()->update(['writings' => 0]);
        config(['services.anthropic.budget_usd' => 0]);
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->assertCount(3, $this->writer->calls);
    }

    public function test_un_refus_du_redacteur_laisse_le_rapport_en_echec_relancable(): void
    {
        $this->writer->error = SurgeryReportWriterFake::refusal();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.status', 'failed');
    }

    public function test_avant_la_fin_de_l_intervention_pas_de_brouillon(): void
    {
        $this->intervention->forceFill(['statut' => StatutIntervention::Cours])->save();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertCount(0, $this->writer->calls);
    }

    public function test_les_consignes_du_redacteur_interdisent_d_inventer_et_d_interpreter(): void
    {
        $instructions = (new \ReflectionClassConstant(SurgeryReportWriterClaude::class, 'INSTRUCTIONS'))->getValue();
        $this->assertStringContainsString('N\'invente rien et n\'interprète rien', $instructions);
        $this->assertStringContainsString('Une phrase sans source n\'existe pas', $instructions);
        $this->assertStringContainsString('reste vide', $instructions);
    }

    /* ————— 1re vérification (09.11.2026) ————— */

    public function test_reverb_injoignable_ne_bloque_jamais_le_rapport(): void
    {
        $broadcast = $this->mock(BroadcastFactory::class);
        $broadcast->shouldReceive('queue')->andThrow(new BroadcastException('Reverb injoignable'));

        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.status', 'draft');
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->uri('/complements'), ['text' => 'Ajout.'], $this->enTetes($this->jeton))->assertCreated();
    }

    public function test_un_nouveau_brouillon_garde_les_corrections_du_veterinaire(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['complications' => 'Aucune.', 'aftercare' => 'Collerette pendant 14 jours.']], $this->enTetes($this->jeton));

        $this->writer->result['sections']['intervention'] = [['text' => 'Orchiectomie chez Filou.', 'sources' => ['E1']]];
        $this->writer->result['sections']['aftercare'] = [['text' => 'Collerette pendant 10 jours, contrôle à J10.', 'sources' => ['N2']]];
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            // Corrigées : gardées
            ->assertJsonPath('data.sections.complications', 'Aucune.')
            ->assertJsonPath('data.sections.aftercare', 'Collerette pendant 14 jours.')
            // Non touchée : le nouveau texte
            ->assertJsonPath('data.sections.intervention', 'Orchiectomie chez Filou.');
    }

    public function test_une_dictee_finie_apres_des_corrections_ne_les_remplace_pas(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['complications' => 'Aucune.']], $this->enTetes($this->jeton));
        $this->dicter();

        $this->assertCount(2, $this->writer->calls);
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.sections.complications', 'Aucune.');
    }

    public function test_avant_le_reveil_ni_correction_ni_validation_ni_dictee(): void
    {
        $this->intervention->forceFill(['statut' => StatutIntervention::Cours])->save();
        $this->patchJson($this->uri(), ['sections' => ['complications' => 'x']], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_pas_de_validation_pendant_une_dictee(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->assertCreated();
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('report');
    }

    public function test_la_dictee_d_un_rapport_de_bloc_ne_se_termine_qu_avec_le_droit_bloc(): void
    {
        $id = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        ['employe' => $sansBloc, 'jeton' => $autre] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScopes()->create([
            'organisation_id' => $sansBloc->organisation_id,
            'nom' => 'Accueil',
            'domaines' => array_values(array_filter(DomainePermission::cases(), fn (DomainePermission $d) => $d !== DomainePermission::Bloc)),
        ]);
        $sansBloc->forceFill(['role_id' => $role->id])->save();

        $this->deleteJson("/api/v1/enregistrements/{$id}", [], $this->enTetes($autre))->assertForbidden();
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($autre))->assertForbidden();
    }

    public function test_les_lignes_d_a_terminer_disent_si_l_intervention_est_close(): void
    {
        $line = collect($this->getJson('/api/v1/unfinished-work', $this->enTetes($this->jeton))->json('data.groups'))->firstWhere('kind', 'surgery_report')['lines'][0];
        $this->assertSame('fini', $line['status']);
    }

    /* ————— 2e vérification (09.11.2026) ————— */

    public function test_une_rubrique_videe_expres_n_est_pas_remplie_par_le_brouillon_suivant(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['technique' => '']], $this->enTetes($this->jeton))->assertJsonPath('data.edited_sections', ['technique']);
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.sections.technique', '')
            // Le brouillon garde ce que l'IA propose, pour la fenêtre
            ->assertJsonPath('data.draft.sections.technique.0.text', 'Abord préscrotal, technique fermée.');
    }

    public function test_une_dictee_prete_pendant_une_redaction_en_lance_une_nouvelle(): void
    {
        SurgeryReport::query()->create(['intervention_id' => $this->intervention->id, 'status' => 'writing', 'writings' => 1]);
        $this->dicter();
        $this->assertTrue(SurgeryReport::first()->is_redraft_pending);
        $this->assertCount(0, $this->writer->calls);

        // La rédaction en cours se termine : la suivante part, avec la dictée
        (new WriteSurgeryReport($this->intervention->id))->handle($this->writer, app(SurgeryReportSources::class), app(SurgeryReportDrafts::class));
        $this->assertCount(2, $this->writer->calls);
        $this->assertNotSame([], collect($this->writer->calls[1])->where('kind', 'dictation')->all());
        $this->assertFalse(SurgeryReport::first()->is_redraft_pending);
        $this->assertSame(2, SurgeryReport::first()->writings);
    }

    public function test_valider_annonce_la_dictee_close_aux_autres_postes(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->dicter();
        Event::fake([EnregistrementMisAJour::class]);
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton))->assertOk();
        Event::assertDispatched(EnregistrementMisAJour::class, fn ($e) => $e->broadcastWith()['statut'] === 'valide' && $e->broadcastWith()['intervention_id'] === $this->intervention->id);
    }

    public function test_l_audio_d_une_dictee_de_bloc_demande_le_droit_bloc(): void
    {
        $id = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        ['employe' => $sansBloc, 'jeton' => $autre] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScopes()->create([
            'organisation_id' => $sansBloc->organisation_id,
            'nom' => 'Accueil',
            'domaines' => array_values(array_filter(DomainePermission::cases(), fn (DomainePermission $d) => $d !== DomainePermission::Bloc)),
        ]);
        $sansBloc->forceFill(['role_id' => $role->id])->save();
        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', TranscripteurFactice::ENTETE.'AA'),
        ], [...$this->enTetes($autre), 'Accept' => 'application/json'])->assertForbidden();
    }

    /* ————— 3e vérification (09.11.2026) ————— */

    public function test_remplacer_par_le_texte_du_brouillon_rend_la_rubrique_non_touchee(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['technique' => 'Abord préscrotal.']], $this->enTetes($this->jeton))
            ->assertJsonPath('data.edited_sections', ['technique'])
            ->assertJsonPath('data.edited_at.technique', fn ($at) => is_string($at));
        $this->patchJson($this->uri(), ['sections' => ['technique' => 'Abord préscrotal, technique fermée.']], $this->enTetes($this->jeton))
            ->assertJsonPath('data.edited_sections', []);
    }

    public function test_une_redaction_echouee_oublie_la_dictee_en_attente(): void
    {
        SurgeryReport::query()->create(['intervention_id' => $this->intervention->id, 'status' => 'writing', 'writings' => 1, 'is_redraft_pending' => true]);
        $this->writer->error = SurgeryReportWriterFake::refusal();
        (new WriteSurgeryReport($this->intervention->id))->handle($this->writer, app(SurgeryReportSources::class), app(SurgeryReportDrafts::class));
        $report = SurgeryReport::first();
        $this->assertSame('failed', $report->status);
        $this->assertFalse($report->is_redraft_pending);
        $this->assertSame(1, $report->writings);
    }

    public function test_une_dictee_bloquee_en_transcription_s_abandonne_et_la_validation_repart(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $id = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        // Deux parties annoncées, aucune envoyée (téléphone éteint)
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 2], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->deleteJson("/api/v1/enregistrements/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();
        $this->postJson($this->uri('/validate'), [], $this->enTetes($this->jeton))->assertOk();
    }

    /* ————— 4e vérification (09.11.2026) ————— */

    public function test_une_correction_est_annoncee_aux_autres_ecrans(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        Event::fake([InterventionUpdated::class]);
        $this->patchJson($this->uri(), ['sections' => ['complications' => 'Aucune.']], $this->enTetes($this->jeton))->assertOk();
        Event::assertDispatched(InterventionUpdated::class, fn ($e) => $e->intervention->id === $this->intervention->id);
    }

    public function test_completer_une_dictee_en_ajoute_une_neuve_et_l_abandon_ne_touche_que_l_ajout(): void
    {
        $this->dicter();
        $first = EnregistrementConsultation::withoutGlobalScopes()->where('intervention_id', $this->intervention->id)->value('id');
        // Compléter : une dictée neuve, la première reste prête
        $second = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.prochaine_partie', 1)
            ->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertSame(StatutEnregistrement::Pret, EnregistrementConsultation::withoutGlobalScopes()->find($first)->statut);

        // L'ajout abandonné : la première dictée est intacte et nourrit encore le brouillon
        $this->deleteJson("/api/v1/enregistrements/{$second}", [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertSame('pret', EnregistrementConsultation::withoutGlobalScopes()->findOrFail($first)->statut->value);
        SurgeryReport::query()->update(['writings' => 0]);
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->assertSame(['D1', 'D2'], collect(collect($this->writer->calls)->last())->where('kind', 'dictation')->pluck('id')->values()->all());
    }

    public function test_deux_dictees_nourrissent_le_brouillon_dans_l_ordre(): void
    {
        $this->dicter();
        $this->dicter();
        $dictation = collect(collect($this->writer->calls)->last())->where('kind', 'dictation')->values();
        $this->assertSame(['D1', 'D2', 'D3', 'D4'], $dictation->pluck('id')->all());
        $this->assertSame('Dictée 1, 0:00', $dictation[0]['label']);
        $this->assertSame('Dictée 2, 0:12', $dictation[3]['label']);
    }

    public function test_supprimer_une_premiere_dictee_l_abandonne_toute(): void
    {
        $id = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        $this->deleteJson("/api/v1/enregistrements/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertSame(StatutEnregistrement::Abandonne, EnregistrementConsultation::withoutGlobalScopes()->find($id)->statut);
    }

    public function test_une_taille_de_fil_reste_une_taille(): void
    {
        $this->assertSame('Monocryl 3-0, peau en 2-0, isoflurane 1,5 à 2 %.', SurgeryReportDrafts::undash('Monocryl 3–0, peau en 2–0, isoflurane 1,5–2 %.'));
    }

    public function test_une_redaction_qui_part_efface_la_dictee_en_attente(): void
    {
        SurgeryReport::query()->create(['intervention_id' => $this->intervention->id, 'status' => 'draft', 'writings' => 1, 'is_redraft_pending' => true]);
        Queue::fake();
        app(SurgeryReportDrafts::class)->request($this->intervention);
        $this->assertFalse(SurgeryReport::first()->is_redraft_pending);
    }

    public function test_la_relance_apres_une_dictee_attend_la_fin_de_la_redaction_en_cours(): void
    {
        SurgeryReport::query()->create(['intervention_id' => $this->intervention->id, 'status' => 'writing', 'writings' => 1, 'is_redraft_pending' => true]);
        Queue::fake();
        (new WriteSurgeryReport($this->intervention->id))->handle($this->writer, app(SurgeryReportSources::class), app(SurgeryReportDrafts::class));
        Queue::assertPushed(WriteSurgeryReport::class, fn ($job) => $job->delay !== null);
    }

    /* ————— 6e vérification (09.11.2026) ————— */

    public function test_le_rapport_sert_toutes_les_dictees_et_le_brouillon_dit_lesquelles_il_a_prises(): void
    {
        $this->dicter();
        $first = EnregistrementConsultation::withoutGlobalScopes()->where('intervention_id', $this->intervention->id)->value('id');
        // Une 2e dictée dont la rédaction est refusée (plafond atteint)
        SurgeryReport::query()->update(['writings' => 3]);
        $this->dicter();

        $data = $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertCount(2, $data['dictations']);
        $this->assertSame('Il boite depuis trois jours, surtout le matin.', $data['dictations'][1]['segments'][0]['texte']);
        $this->assertSame([$first], array_values(array_unique(array_column(array_filter($data['draft']['sources'], fn ($s) => $s['kind'] === 'dictation'), 'recording_id'))));
    }

    /* ————— 7e vérification (09.11.2026) ————— */

    public function test_une_redaction_refusee_par_le_plafond_est_annoncee(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        SurgeryReport::query()->update(['writings' => 3]);
        Event::fake([InterventionUpdated::class]);
        app(SurgeryReportDrafts::class)->request($this->intervention, isDictation: true);
        Event::assertDispatched(InterventionUpdated::class);
    }

    /* ————— 12e vérification (09.11.2026) ————— */

    public function test_a_terminer_compte_aussi_le_rapport_d_une_intervention_au_reveil(): void
    {
        $this->intervention->forceFill(['statut' => StatutIntervention::Reveil, 'prevu_le' => now()])->save();
        $kinds = collect($this->getJson('/api/v1/unfinished-work', $this->enTetes($this->jeton))->json('data.groups'))->pluck('kind')->all();
        $this->assertContains('surgery_report', $kinds);
    }

    public function test_une_intervention_d_avant_restee_au_reveil_reste_a_terminer_meme_rapport_valide(): void
    {
        $this->intervention->forceFill(['statut' => StatutIntervention::Reveil, 'prevu_le' => now()->subDays(2)])->save();
        SurgeryReport::query()->create(['intervention_id' => $this->intervention->id, 'status' => 'validated', 'sections' => ['technique' => 'x'], 'validated_at' => now()]);
        $kinds = collect($this->getJson('/api/v1/unfinished-work', $this->enTetes($this->jeton))->json('data.groups'))->pluck('kind')->all();
        $this->assertContains('surgery_report', $kinds);
    }

    /* ————— 14e vérification (09.11.2026) ————— */

    public function test_un_morceau_vide_est_refuse_sans_laisser_de_partie(): void
    {
        $id = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        $this->post("/api/v1/enregistrements/{$id}/parties/2", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', ''),
        ], [...$this->enTetes($this->jeton), 'Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, PartieEnregistrement::query()->where('enregistrement_consultation_id', $id)->count());
    }

    /* ————— 15e vérification (09.11.2026) ————— */

    public function test_chaque_redaction_compte_dans_le_budget_du_mois(): void
    {
        $before = CompteurMensuel::valeur('anthropic:redactions');
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->assertSame($before + 1, CompteurMensuel::valeur('anthropic:redactions'));
    }

    /* ————— 16e vérification (09.11.2026) ————— */

    public function test_un_doute_garde_son_passage_quand_les_tirets_sont_retires(): void
    {
        $sources = [['id' => 'N1', 'kind' => 'note', 'label' => 'Note', 'text' => 'Monocryl 3-0']];
        $raw = [
            'sections' => ['technique' => [
                ['text' => 'Fermeture au Monocryl 3–0.', 'sources' => ['N1']],
                ['text' => 'Infiltration à 1,5–2 %.', 'sources' => ['N1']],
            ]],
            'doubts' => [
                ['section' => 'technique', 'passage' => 'Monocryl 3–0', 'heard' => 'trois–zéro', 'reason' => 'Taille du fil mal entendue'],
                ['section' => 'technique', 'passage' => '1,5–2 %', 'heard' => '', 'reason' => 'Concentration à confirmer'],
            ],
        ];
        $doubts = app(SurgeryReportDrafts::class)->clean($raw, $sources)['doubts'];
        $this->assertSame(['Monocryl 3-0', '1,5 à 2 %'], array_column($doubts, 'passage'));
        $this->assertSame('trois, zéro', $doubts[0]['heard']);
    }

    public function test_une_clinique_soeur_ne_touche_pas_a_la_dictee(): void
    {
        $id = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        app(ContexteClinique::class)->oublier();
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $autre] = $this->employeAvecJeton($soeur);
        $entetes = [...$this->enTetes($autre), 'Accept' => 'application/json'];

        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', TranscripteurFactice::ENTETE.'AA'),
        ], $entetes)->assertNotFound();
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $entetes)->assertNotFound();
        $this->postJson("/api/v1/enregistrements/{$id}/relancer", [], $entetes)->assertNotFound();
        $this->deleteJson("/api/v1/enregistrements/{$id}", [], $entetes)->assertNotFound();
        $this->assertSame(StatutEnregistrement::Enregistrement, EnregistrementConsultation::query()->findOrFail($id)->statut);
    }

    private function dicter(): void
    {
        $id = $this->postJson("/api/v1/bloc/interventions/{$this->intervention->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', TranscripteurFactice::ENTETE.'AA'),
        ], [...$this->enTetes($this->jeton), 'Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_une_autre_organisation_ne_voit_rien(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        app(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());
        $this->getJson($this->uri(), $this->enTetes($autre))->assertNotFound();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($autre))->assertNotFound();
        $this->postJson($this->uri('/validate'), [], $this->enTetes($autre))->assertNotFound();
    }
}
