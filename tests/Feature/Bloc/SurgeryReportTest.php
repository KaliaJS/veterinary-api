<?php

namespace Tests\Feature\Bloc;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutIntervention;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\EnregistrementConsultation;
use App\Models\Intervention;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Salle;
use App\Models\SurgeryReport;
use App\Services\Bloc\SurgeryReportWriter;
use App\Services\Bloc\SurgeryReportWriterClaude;
use App\Services\Transcription\Transcripteur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    private Employe $chirurgien;

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
        ['employe' => $this->chirurgien, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anna Meyer']);
        $patient = Patient::factory()->for(Client::factory()->create(['organisation_id' => $organisation->id]))->create(['nom' => 'Filou']);
        $salle = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id, 'nom' => 'Salle 1']);

        $this->intervention = Intervention::query()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $patient->id,
            'salle_id' => $salle->id,
            'salle_nom' => 'Salle 1',
            'chirurgien_id' => $this->chirurgien->id,
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
