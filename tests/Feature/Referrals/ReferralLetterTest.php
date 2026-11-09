<?php

namespace Tests\Feature\Referrals;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\Espece;
use App\Enums\StatutEnregistrement;
use App\Mail\RecordShareLink;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\Document;
use App\Models\EnregistrementConsultation;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\ReferralLetter;
use App\Models\RendezVous;
use App\Models\Role;
use App\Models\User;
use App\Services\CompteurMensuel;
use App\Services\Referrals\ReferralLetterWriter;
use App\Services\Referrals\ReferralLetterWriterClaude;
use App\Services\Transcription\Transcripteur;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\ReferralLetterWriterFake;
use Tests\Doubles\TranscripteurFactice;
use Tests\TestCase;

/**
 * La lettre de référence vers un confrère (choix de Jim, 09.11.2026), lot
 * 2a : créée depuis le dossier avec ses pièces jointes et son destinataire,
 * les notes tapées ou dictées, le brouillon de l'IA depuis les pièces et
 * les notes (chaque phrase citée, rien d'inventé, rubriques vides à
 * compléter), l'identité et le traitement en cours recopiés, la signature
 * (droit de signer : figée, rangée au dossier), l'envoi par un lien dont la
 * page montre la lettre en tête. Budget et plafond de l'IA.
 */
class ReferralLetterTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    private RendezVous $consultation;

    private ReferralLetterWriterFake $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 14:00', 'Europe/Zurich'));
        Storage::fake('prive');
        Mail::fake();
        $this->app->instance(ReferralLetterWriter::class, $this->writer = new ReferralLetterWriterFake);
        $this->app->instance(Transcripteur::class, new TranscripteurFactice);

        $this->clinique = Clinique::factory()->create(['nom' => 'Clinique des Deux Rives']);
        $organisation = $this->clinique->societe->organisation;
        $organisation->forceFill(['stripe_client_id' => 'cus_1', 'stripe_moyen_paiement_id' => 'pm_1', 'statut_abonnement' => 'actif'])->save();
        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anna Meyer']);
        $client = Client::factory()->create(['organisation_id' => $organisation->id, 'user_id' => User::factory()->create()->id, 'prenom' => 'Marc', 'nom' => 'Favre', 'tel' => '079 555 12 34']);
        $this->patient = Patient::factory()->for($client)->create(['nom' => 'Rocky', 'espece' => Espece::Chien, 'race' => 'Berger allemand', 'puce' => '756098123456789']);

        $this->consultation = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'reason' => 'Boiterie', 'starts_at' => now()->subDays(7), 'ends_at' => now()->subDays(7)->addMinutes(20)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $this->consultation->id, 'signe_le' => now()->subDays(7), 's' => 'Boiterie postérieure gauche depuis 3 semaines.', 'o' => 'Tiroir positif au genou gauche.', 'a' => '', 'p' => '']);

        // Le traitement en cours : une ordonnance active de la semaine
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'organisation_id' => $organisation->id, 'patient_id' => $this->patient->id, 'employe_id' => $employe->id, 'signee_le' => now()->subDays(7)]);
        OrdonnanceLigne::factory()->create(['ordonnance_id' => $ordonnance->id, 'produit_id' => Produit::factory()->medicament()->create(['organisation_id' => $organisation->id, 'nom' => 'Metacam 1,5 mg/ml'])->id, 'posologie' => '1,5 ml par jour', 'duree' => '10 jours']);

        app(ContexteClinique::class)->oublier();
    }

    private function create(array $extra = []): ReferralLetter
    {
        $id = $this->postJson("/api/v1/patients/{$this->patient->id}/referral-letters", [
            'items' => [['type' => 'consultation', 'id' => $this->consultation->id]],
            'recipient_name' => 'Dr Lukas Brunner',
            'recipient_clinic' => 'Centre de référence vétérinaire, Lausanne',
            'recipient_email' => 'l.brunner@crv.ch',
            ...$extra,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        return ReferralLetter::withoutGlobalScopes()->findOrFail($id);
    }

    private function uri(ReferralLetter $letter, string $suffix = ''): string
    {
        return "/api/v1/referral-letters/{$letter->id}".$suffix;
    }

    private function notes(ReferralLetter $letter): void
    {
        $this->patchJson($this->uri($letter), ['notes' => "suspicion rupture LCCr gauche\navis chirurgical, TPLO ?"], $this->enTetes($this->jeton))->assertOk();
    }

    private function signed(): ReferralLetter
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->postJson($this->uri($letter, '/sign'), [], $this->enTetes($this->jeton))->assertOk();

        return $letter->refresh();
    }

    public function test_la_lettre_se_cree_depuis_le_dossier_avec_ses_pieces_et_son_destinataire(): void
    {
        $letter = $this->create();
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.patient_name', 'Rocky')
            ->assertJsonPath('data.items', [['type' => 'consultation', 'id' => $this->consultation->id]])
            ->assertJsonPath('data.recipient_name', 'Dr Lukas Brunner')
            ->assertJsonPath('data.recipient_clinic', 'Centre de référence vétérinaire, Lausanne')
            ->assertJsonPath('data.recipient_email', 'l.brunner@crv.ch')
            ->assertJsonPath('data.status', 'empty');
        // La liste, en bref : la lettre entière se lit une à une
        $this->getJson("/api/v1/patients/{$this->patient->id}/referral-letters", $this->enTetes($this->jeton))
            ->assertJsonPath('data.0.id', $letter->id)
            ->assertJsonPath('data.0.is_signed', false)
            ->assertJsonPath('data.0.recipient_name', 'Dr Lukas Brunner')
            ->assertJsonMissingPath('data.0.preview_sources');
        // Une adresse refusée l'est en français, sous son champ
        $this->patchJson($this->uri($letter), ['recipient_email' => 'l.brunner@'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonPath('errors.recipient_email.0', 'Cette adresse e-mail n’est pas valable.');
    }

    public function test_seulement_des_pieces_de_cet_animal(): void
    {
        $other = Patient::factory()->create(['organisation_id' => $this->patient->organisation_id]);
        $foreign = RendezVous::factory()->pourPatient($other)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(20)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $foreign->id, 'signe_le' => now()]);
        $this->postJson("/api/v1/patients/{$this->patient->id}/referral-letters", ['items' => [['type' => 'consultation', 'id' => $foreign->id]]], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_le_brouillon_part_des_pieces_jointes_et_des_notes(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $sources = collect($this->writer->calls[0]);
        $this->assertSame(['P1', 'P2', 'N1', 'N2'], $sources->pluck('id')->all());
        $this->assertSame('Boiterie postérieure gauche depuis 3 semaines.', $sources->firstWhere('id', 'P1')['text']);
        $this->assertStringContainsString('motif et anamnèse', $sources->firstWhere('id', 'P1')['label']);
        $this->assertSame(['suspicion rupture LCCr gauche', 'avis chirurgical, TPLO ?'], $sources->where('kind', 'note')->pluck('text')->values()->all());
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.draft.sections.history.0.sources', ['P1'])
            ->assertJsonPath('data.sections.reason', 'Suspicion de rupture du ligament croisé crânial gauche.');
    }

    public function test_les_notes_peuvent_aussi_etre_dictees(): void
    {
        $letter = $this->create();
        $id = $this->postJson($this->uri($letter, '/enregistrement'), [], $this->enTetes($this->jeton))->assertCreated()->assertJsonPath('data.referral_letter_id', $letter->id)->json('data.id');
        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', TranscripteurFactice::ENTETE.'AA'),
        ], [...$this->enTetes($this->jeton), 'Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(StatutEnregistrement::Pret, EnregistrementConsultation::withoutGlobalScopes()->find($id)->statut);
        $this->assertCount(1, $this->writer->calls);
        $this->assertSame(['D1', 'D2'], collect($this->writer->calls[0])->where('kind', 'dictation')->pluck('id')->values()->all());
    }

    public function test_sans_source_reelle_une_phrase_est_retiree_et_une_rubrique_vide_reste_a_completer(): void
    {
        $this->writer->result['sections']['exams'] = [['text' => 'Radiographie inventée.', 'sources' => ['P9']]];
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))
            ->assertJsonPath('data.draft.sections.exams', [])
            ->assertJsonPath('data.sections.exams', '');
    }

    public function test_les_rubriques_dans_l_ordre_et_ce_qui_est_recopie(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        $response = $this->getJson($this->uri($letter), $this->enTetes($this->jeton))
            ->assertJsonPath('data.order', ['patient', 'reason', 'history', 'exams', 'treatment', 'request', 'attachments'])
            ->assertJsonPath('data.section_labels.exams', 'Examens complémentaires');
        $this->assertSame(['patient', 'reason', 'history', 'treatment', 'request', 'attachments'], array_column($response->json('data.blocks'), 'key'));
        $copied = $response->json('data.copied');
        $this->assertStringStartsWith('Rocky, Chien, Berger allemand', $copied['patient'][0]);
        $this->assertSame('Puce 756098123456789', $copied['patient'][1]);
        $this->assertStringContainsString('Marc Favre, 079 555 12 34', $copied['patient'][2]);
        $this->assertSame(['Metacam 1,5 mg/ml : 1,5 ml par jour, 10 jours, depuis le 02.11.2026'], $copied['treatment']);
        $this->assertSame(['Consultation : Boiterie, 02.11.2026'], $copied['attachments']);
        // Le traitement en cours n'est pas donné à l'IA
        $this->assertStringNotContainsString('Metacam', json_encode($this->writer->calls[0], JSON_UNESCAPED_UNICODE));
    }

    public function test_le_veterinaire_corrige_avant_de_signer(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri($letter), ['sections' => ['exams' => 'Radiographies du genou gauche du 3.11.']], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.sections.exams', 'Radiographies du genou gauche du 3.11.')->assertJsonPath('data.edited_sections', ['exams']);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))->assertJsonPath('data.sections.exams', 'Radiographies du genou gauche du 3.11.');
    }

    public function test_des_pieces_ou_des_notes_changees_depuis_le_brouillon_le_disent(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', false);
        // Une rubrique corrigée ne rend pas le brouillon périmé ; une note changée, si
        $this->patchJson($this->uri($letter), ['sections' => ['request' => 'Merci.']], $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', false);
        $this->travel(1)->minutes();
        $this->patchJson($this->uri($letter), ['notes' => "boiterie PG\nTPLO ?"], $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', true);
        $this->travel(1)->minutes();
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', false);
        // Les notes des visites changées aussi
        $this->travel(1)->minutes();
        $this->patchJson($this->uri($letter), ['visit_notes' => true], $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', true);
    }

    public function test_deuxieme_verification_brouillon_perime_ordre_des_pieces_et_dictee(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        // Le même choix dans un autre ordre n'est pas un changement
        $second = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addMinutes(20)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $second->id, 'signe_le' => now()->subDays(3)]);
        app(ContexteClinique::class)->oublier();
        $both = [['type' => 'consultation', 'id' => $this->consultation->id], ['type' => 'consultation', 'id' => $second->id]];
        $this->travel(1)->minutes();
        $this->patchJson($this->uri($letter), ['items' => $both], $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', true);
        $this->travel(1)->minutes();
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        $this->travel(1)->minutes();
        $this->patchJson($this->uri($letter), ['items' => array_reverse($both)], $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', false);
        // Une nouvelle rédaction refusée (plafond) : le brouillon d'avant reste dit périmé
        $this->travel(1)->minutes();
        $this->patchJson($this->uri($letter), ['notes' => 'autre motif'], $this->enTetes($this->jeton));
        ReferralLetter::withoutGlobalScopes()->whereKey($letter->id)->update(['writings' => 3]);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', true);
    }

    public function test_deuxieme_verification_une_dictee_transcrite_rend_le_brouillon_perime_si_la_redaction_est_refusee(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        ReferralLetter::withoutGlobalScopes()->whereKey($letter->id)->update(['writings' => 3]);
        $this->travel(1)->minutes();
        $id = $this->postJson($this->uri($letter, '/enregistrement'), [], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', TranscripteurFactice::ENTETE.'AA'),
        ], [...$this->enTetes($this->jeton), 'Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertOk();
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))->assertJsonPath('data.is_draft_stale', true);
    }

    public function test_deuxieme_verification_une_piece_sortie_du_dossier_ne_bloque_ni_la_lettre_ni_le_lien(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        $letter->refresh();
        // Le compte rendu dévalidé : il sort du dossier partageable
        CompteRendu::query()->where('rendez_vous_id', $this->consultation->id)->update(['signe_le' => null]);
        app(ContexteClinique::class)->oublier();
        $this->patchJson($this->uri($letter), ['items' => $letter->items, 'notes' => 'toujours enregistré'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.notes', 'toujours enregistré');
        // Signée avec la pièce, puis la pièce sortie : le lien part quand même, sans elle
        CompteRendu::query()->where('rendez_vous_id', $this->consultation->id)->update(['signe_le' => now()]);
        app(ContexteClinique::class)->oublier();
        $this->patchJson($this->uri($letter), ['items' => [['type' => 'consultation', 'id' => $this->consultation->id]]], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->uri($letter, '/sign'), [], $this->enTetes($this->jeton))->assertOk();
        CompteRendu::query()->where('rendez_vous_id', $this->consultation->id)->update(['signe_le' => null]);
        app(ContexteClinique::class)->oublier();
        $token = basename((string) $this->postJson($this->uri($letter, '/link'), ['days' => 30], $this->enTetes($this->jeton))->assertCreated()->json('data.url'));
        $this->assertSame(['referral_letter'], array_column($this->getJson("/api/v1/public/record-shares/{$token}")->json('data.items'), 'type'));
    }

    public function test_deuxieme_verification_la_lettre_signee_ne_se_joint_pas_deux_fois(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri($letter, '/sign'), [], $this->enTetes($this->jeton))->assertOk();
        $groups = $this->getJson("/api/v1/patients/{$this->patient->id}/share-items", $this->enTetes($this->jeton))->json('data');
        $types = collect($groups)->flatMap(fn (array $g) => $g['items'])->pluck('type')->all();
        $this->assertContains('referral_letter', $types);
        $this->assertNotContains('document', $types);
    }

    public function test_une_file_d_attente_qui_refuse_la_tache_ne_laisse_pas_la_lettre_en_redaction(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('File indisponible'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
            $this->fail('La tâche aurait dû être refusée.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('failed', $letter->refresh()->status);
        $this->assertSame('writer', $letter->error);
    }

    public function test_signer_demande_le_droit_et_fige_la_lettre_rangee_au_dossier(): void
    {
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        ['employe' => $sansDroit, 'jeton' => $assistant] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScopes()->create(['organisation_id' => $sansDroit->organisation_id, 'nom' => 'Aide', 'domaines' => array_values(array_filter(DomainePermission::cases(), fn ($d) => $d !== DomainePermission::SignerComptesRendus))]);
        $sansDroit->forceFill(['role_id' => $role->id])->save();
        $this->postJson($this->uri($letter, '/sign'), [], $this->enTetes($assistant))->assertForbidden();
        app(ContexteClinique::class)->oublier();

        $this->postJson($this->uri($letter, '/sign'), [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.status', 'signed')->assertJsonPath('data.signed_by_name', 'Dre Anna Meyer');
        // Figée
        $this->patchJson($this->uri($letter), ['sections' => ['exams' => 'Plus tard.']], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->patchJson($this->uri($letter), ['notes' => 'Plus tard.'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->uri($letter, '/enregistrement'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        // Rangée dans les documents de l'animal, en PDF
        $document = Document::query()->findOrFail($letter->refresh()->document_id);
        $this->assertSame('lettre_reference', $document->type);
        $this->assertSame('Lettre de référence : Dr Lukas Brunner', $document->nom);
        $this->assertStringStartsWith('%PDF', Storage::disk('prive')->get($document->chemin));
        $this->assertSame($this->patient->id, $document->patient_id);
    }

    public function test_le_document_range_porte_le_jour_de_la_clinique(): void
    {
        $this->clinique->forceFill(['fuseau_horaire' => 'Europe/Zurich'])->save();
        $letter = $this->create();
        $this->notes($letter);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton));
        // 23:30 UTC, déjà le lendemain à Zurich
        $this->travelTo(CarbonImmutable::parse('2026-11-09 23:30:00', 'UTC'));
        $this->postJson($this->uri($letter, '/sign'), [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame('2026-11-10', Document::query()->findOrFail($letter->refresh()->document_id)->etabli_le->toDateString());
    }

    public function test_envoi_b_un_lien_dont_la_page_montre_la_lettre_en_tete(): void
    {
        $letter = $this->create();
        $this->postJson($this->uri($letter, '/link'), ['days' => 30], $this->enTetes($this->jeton))->assertUnprocessable();
        $letter = $this->signed();
        $response = $this->postJson($this->uri($letter, '/link'), ['days' => 30], $this->enTetes($this->jeton))->assertCreated()->assertJsonPath('data.share.recipient_email', 'l.brunner@crv.ch');
        Mail::assertSent(RecordShareLink::class, fn (RecordShareLink $m) => $m->hasTo('l.brunner@crv.ch'));
        $token = basename((string) $response->json('data.url'));
        $items = $this->getJson("/api/v1/public/record-shares/{$token}")->assertOk()->json('data.items');
        $this->assertSame(['referral_letter', 'consultation'], array_column($items, 'type'));
        $this->getJson("/api/v1/public/record-shares/{$token}/items/referral_letter/{$letter->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Lettre de référence à Dr Lukas Brunner')
            ->assertJsonPath('data.sections.1.label', 'Motif de la référence');
        // Où en est la lettre : envoyée, puis ouverte
        $this->getJson($this->uri($letter), $this->enTetes($this->jeton))->assertJsonPath('data.share.opened_at', now()->toIso8601String());
    }

    public function test_les_redactions_restent_dans_le_budget_et_le_plafond(): void
    {
        $letter = $this->create();
        foreach (range(1, 3) as $i) {
            $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        }
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('letter');
        $this->assertCount(3, $this->writer->calls);
        $this->assertSame(3, CompteurMensuel::valeur('anthropic:redactions'));
        ReferralLetter::withoutGlobalScopes()->update(['writings' => 0]);
        config(['services.anthropic.budget_usd' => 0]);
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_les_consignes_du_redacteur_interdisent_d_inventer(): void
    {
        $instructions = (new \ReflectionClassConstant(ReferralLetterWriterClaude::class, 'INSTRUCTIONS'))->getValue();
        $this->assertStringContainsString('N\'invente rien et n\'interprète rien', $instructions);
        $this->assertStringContainsString('Une phrase sans source n\'existe pas', $instructions);
        $this->assertStringContainsString('en langage vétérinaire', $instructions);
        $this->assertStringContainsString('son traitement en cours et la liste des pièces jointes sont ajoutés à part', $instructions);
    }

    public function test_une_autre_clinique_ne_voit_rien(): void
    {
        $letter = $this->create();
        app(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());
        $this->getJson($this->uri($letter), $this->enTetes($autre))->assertNotFound();
        $this->postJson($this->uri($letter, '/draft'), [], $this->enTetes($autre))->assertNotFound();
        $this->postJson($this->uri($letter, '/enregistrement'), [], $this->enTetes($autre))->assertNotFound();
    }
}
