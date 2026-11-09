<?php

namespace Tests\Feature\Hospitalisation;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\Espece;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutIntervention;
use App\Enums\StatutOrdonnance;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Jobs\WriteDischargeLetter;
use App\Mail\ReponseClinique;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DischargeLetter;
use App\Models\Employe;
use App\Models\EnregistrementConsultation;
use App\Models\Intervention;
use App\Models\LignePlanning;
use App\Models\MessageClientele;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Role;
use App\Models\Salle;
use App\Models\Sejour;
use App\Models\StaySheet;
use App\Models\SurgeryReport;
use App\Models\User;
use App\Services\CompteurMensuel;
use App\Services\Hospitalisation\DischargeLetters;
use App\Services\Hospitalisation\DischargeLetterSources;
use App\Services\Hospitalisation\DischargeLetterWriter;
use App\Services\Hospitalisation\DischargeLetterWriterClaude;
use App\Services\Transcription\Transcripteur;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\DischargeLetterWriterFake;
use Tests\Doubles\TranscripteurFactice;
use Tests\TestCase;

/**
 * La lettre de sortie d'hospitalisation (choix de Jim, 09.11.2026 : B, le
 * séjour et la maison). Le brouillon part du séjour, des traitements, des
 * rapports de la journée signés, du rapport de bloc validé, des consignes
 * tapées et, si le vétérinaire a dicté, de la dictée. Chaque phrase cite
 * ses sources ; une phrase sans source réelle est retirée ; une rubrique
 * vide reste à compléter. Les médicaments et le contrôle sont recopiés de
 * l'ordonnance et du planning, sans l'IA. Rien n'est à la lettre avant la
 * signature (droit de signer les comptes rendus), qui la fige ; elle part
 * ensuite au propriétaire. Budget et plafond de l'IA.
 */
class DischargeLetterTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $veterinaire;

    private string $jeton;

    private Patient $patient;

    private Sejour $sejour;

    private DischargeLetterWriterFake $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 14:00', 'Europe/Zurich'));
        Storage::fake('prive');
        Mail::fake();
        $this->app->instance(DischargeLetterWriter::class, $this->writer = new DischargeLetterWriterFake);
        $this->app->instance(Transcripteur::class, new TranscripteurFactice);

        $this->clinique = Clinique::factory()->create(['nom' => 'Clinique des Deux Rives', 'tel' => '032 555 12 34', 'nombre_boxes' => 8]);
        $organisation = $this->clinique->societe->organisation;
        $organisation->forceFill(['stripe_client_id' => 'cus_1', 'stripe_moyen_paiement_id' => 'pm_1', 'statut_abonnement' => 'actif'])->save();
        ['employe' => $this->veterinaire, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anna Meyer']);
        $client = Client::factory()->create(['organisation_id' => $organisation->id, 'user_id' => User::factory()->create()->id]);
        $this->patient = Patient::factory()->for($client)->create(['nom' => 'Bandit', 'espece' => Espece::Chien, 'race' => 'Border collie']);

        $this->sejour = Sejour::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'client_id' => $client->id,
            'veterinaire_id' => $this->veterinaire->id,
            'box' => 3,
            'motif' => 'Corps étranger intestinal',
            'admis_le' => CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Zurich')->utc(),
        ]);

        // Les traitements donnés pendant le séjour
        $this->sejour->treatments()->create([
            'label' => 'Ringer lactate', 'dose' => '5 ml/kg/h IV', 'times' => [],
            'starts_at' => CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-11-07 18:00', 'Europe/Zurich')->utc(),
        ]);

        // Deux rapports signés, un à relire (pas une source)
        $this->sheet('2026-11-06', StaySheet::REPORT_SIGNED, 'Bandit est calme. Premier petit repas ce soir, gardé.');
        $this->sheet('2026-11-07', StaySheet::REPORT_SIGNED, 'Il mange ses repas fractionnés sans vomir.');
        $this->sheet('2026-11-08', StaySheet::REPORT_TO_REVIEW, 'Brouillon pas encore relu.');

        // L'opération du jour de l'admission, rapport de bloc validé
        $salle = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id, 'nom' => 'Salle 1']);
        $intervention = Intervention::query()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'salle_id' => $salle->id, 'salle_nom' => 'Salle 1',
            'chirurgien_id' => $this->veterinaire->id, 'procedure' => 'Entérotomie', 'duree_minutes' => 60,
            'prevu_le' => CarbonImmutable::parse('2026-11-05 08:00', 'Europe/Zurich')->utc(), 'statut' => StatutIntervention::Fini, 'protocole_anesthesique' => [],
        ]);
        SurgeryReport::query()->create([
            'intervention_id' => $intervention->id, 'status' => 'validated', 'validated_at' => now(),
            'sections' => ['technique' => 'Entérotomie jéjunale, retrait d’un noyau de pêche.', 'complications' => 'Aucune complication.'],
        ]);

        app(ContexteClinique::class)->oublier();
    }

    private function sheet(string $day, string $status, string $text): void
    {
        StaySheet::query()->create([
            'sejour_id' => $this->sejour->id, 'day' => $day, 'opened_at' => now(),
            'report_status' => $status, 'report_text' => $text,
            'report_signed_at' => $status === StaySheet::REPORT_SIGNED ? now() : null,
            'report_signed_by_name' => $status === StaySheet::REPORT_SIGNED ? 'Dre Anna Meyer' : null,
        ]);
    }

    private function uri(string $suffix = ''): string
    {
        return "/api/v1/hospitalisation/sejours/{$this->sejour->id}/letter".$suffix;
    }

    private function notes(string $notes = "collerette jusqu'au contrôle\n\nlaisse 10 j, pas d'escaliers"): void
    {
        $this->patchJson($this->uri(), ['home_notes' => $notes], $this->enTetes($this->jeton))->assertOk();
    }

    private function prescription(string $signedOn, string $product, string $posology, string $duration, StatutOrdonnance $status = StatutOrdonnance::Active): Ordonnance
    {
        $ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id, 'organisation_id' => $this->clinique->societe->organisation_id,
            'patient_id' => $this->patient->id, 'employe_id' => $this->veterinaire->id, 'signee_le' => $signedOn, 'statut' => $status,
        ]);
        OrdonnanceLigne::factory()->create([
            'ordonnance_id' => $ordonnance->id,
            'produit_id' => Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => $product])->id,
            'posologie' => $posology, 'duree' => $duration,
        ]);

        return $ordonnance;
    }

    /** L'animal sort : la lettre peut alors se signer (choix de Jim, 09.11.2026) */
    private function sortir(): void
    {
        $this->sejour->forceFill(['sorti_le' => now()])->save();
    }

    private function appointment(string $at, StatutRendezVous $status = StatutRendezVous::Prevu, TypeRendezVous $type = TypeRendezVous::Controle): RendezVous
    {
        $line = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'nom' => 'Dre Anna Meyer']);
        $starts = CarbonImmutable::parse($at, 'Europe/Zurich')->utc();

        return RendezVous::factory()->pourPatient($this->patient)->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $line->id, 'status' => $status, 'type' => $type,
            'starts_at' => $starts, 'ends_at' => $starts->addMinutes(20),
        ]);
    }

    private function dicter(): int
    {
        $id = $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/enregistrement", [], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.sejour_id', $this->sejour->id)
            ->json('data.id');
        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', TranscripteurFactice::ENTETE.'AA'),
        ], [...$this->enTetes($this->jeton), 'Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertOk();

        return (int) $id;
    }

    private function sansDroit(DomainePermission $droit): string
    {
        app(ContexteClinique::class)->oublier();
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScopes()->create([
            'organisation_id' => $employe->organisation_id,
            'nom' => 'Sans '.$droit->value,
            'domaines' => array_values(array_filter(DomainePermission::cases(), fn (DomainePermission $d) => $d !== $droit)),
        ]);
        $employe->forceFill(['role_id' => $role->id])->save();

        return $jeton;
    }

    /* ————— Le brouillon et ses sources ————— */

    public function test_le_brouillon_part_du_sejour_des_traitements_des_rapports_signes_du_rapport_de_bloc_et_des_consignes(): void
    {
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();

        $sources = collect($this->writer->calls[0]);
        $this->assertSame(['S1', 'T1', 'R1', 'R2', 'B1', 'B2', 'N1', 'N2'], $sources->pluck('id')->all());
        // Le séjour : l'animal, le motif, les dates, le vétérinaire
        $stay = $sources->firstWhere('id', 'S1')['text'];
        foreach (['Bandit', 'Border collie', 'Motif : Corps étranger intestinal', 'Admis le jeudi 5 novembre', 'Vétérinaire : Dre Anna Meyer'] as $part) {
            $this->assertStringContainsString($part, $stay);
        }
        $this->assertSame('Ringer lactate, 5 ml/kg/h IV, du 5 novembre au 7 novembre', $sources->firstWhere('id', 'T1')['text']);
        // Seuls les rapports signés, avec leur jour
        $this->assertSame('Rapport du vendredi 6 novembre', $sources->firstWhere('id', 'R1')['label']);
        $this->assertNotContains('Brouillon pas encore relu.', $sources->pluck('text')->all());
        // Chaque rubrique non vide du rapport de bloc validé
        $this->assertSame('Entérotomie : Entérotomie jéjunale, retrait d’un noyau de pêche.', $sources->firstWhere('id', 'B1')['text']);
        $this->assertSame('Rapport de bloc, complications', $sources->firstWhere('id', 'B2')['label']);
        // Les consignes, une source par ligne non vide
        $this->assertSame(["collerette jusqu'au contrôle", "laisse 10 j, pas d'escaliers"], $sources->where('kind', 'note')->pluck('text')->values()->all());

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_signed', false)
            ->assertJsonPath('data.draft.sections.during.1.sources', ['R1'])
            ->assertJsonPath('data.sections.home', 'Bandit garde sa collerette jusqu’au contrôle.');
    }

    public function test_un_rapport_de_bloc_non_valide_ou_d_un_autre_animal_n_est_pas_une_source(): void
    {
        SurgeryReport::query()->update(['status' => 'draft']);
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->assertSame([], collect($this->writer->calls[0])->where('kind', 'surgery')->all());
    }

    public function test_la_dictee_des_consignes_nourrit_le_brouillon(): void
    {
        $id = $this->dicter();

        // Transcrite : la dictée est prête, le brouillon de la lettre s'écrit
        $this->assertSame(StatutEnregistrement::Pret, EnregistrementConsultation::withoutGlobalScopes()->find($id)->statut);
        $this->assertCount(1, $this->writer->calls);
        $dictation = collect($this->writer->calls[0])->where('kind', 'dictation')->values();
        $this->assertSame(['D1', 'D2'], $dictation->pluck('id')->all());
        $this->assertSame('Dictée 0:12', $dictation[1]['label']);
        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(1, 'data.dictations');
    }

    public function test_sans_dictee_le_brouillon_part_des_consignes_tapees(): void
    {
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->assertSame([], collect($this->writer->calls[0])->where('kind', 'dictation')->all());
        $this->assertCount(2, collect($this->writer->calls[0])->where('kind', 'note'));
    }

    public function test_sans_source_reelle_une_phrase_est_retiree_et_les_tirets_partent(): void
    {
        $this->writer->result['sections']['call'] = [
            ['text' => 'S’il vomit — appelez-nous.', 'sources' => ['N1']],
            ['text' => 'Appelez-nous s’il a de la fièvre.', 'sources' => ['X9']],
            ['text' => 'Sans source.', 'sources' => []],
        ];
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonCount(1, 'data.draft.sections.call')
            ->assertJsonPath('data.sections.call', 'S’il vomit, appelez-nous.');
    }

    public function test_une_rubrique_dont_aucune_source_ne_parle_reste_a_completer(): void
    {
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.sections.call', '')
            ->assertJsonPath('data.draft.sections.call', []);
    }

    public function test_les_consignes_du_redacteur_interdisent_d_inventer_et_d_interpreter(): void
    {
        $instructions = (new \ReflectionClassConstant(DischargeLetterWriterClaude::class, 'INSTRUCTIONS'))->getValue();
        $this->assertStringContainsString('N\'invente rien et n\'interprète rien', $instructions);
        $this->assertStringContainsString('Une phrase sans source n\'existe pas', $instructions);
        $this->assertStringContainsString('reste vide', $instructions);
        $this->assertStringContainsString('mots simples pour un propriétaire', $instructions);
        $this->assertStringContainsString('recopiés de l\'ordonnance et du planning : ne les écris pas', $instructions);
    }

    /* ————— Les rubriques et ce qui est recopié ————— */

    public function test_la_lettre_a_ses_rubriques_dans_l_ordre(): void
    {
        $this->prescription('2026-11-09', 'Metacam 2,5 mg', '1 comprimé par jour avec le repas', 'pendant 5 jours');
        $this->appointment('2026-11-16 10:30');
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['call' => 'S’il vomit.']], $this->enTetes($this->jeton));

        $response = $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.order', ['stay', 'during', 'medicines', 'home', 'follow_up', 'call'])
            ->assertJsonPath('data.section_labels.medicines', 'Ses médicaments')
            ->assertJsonPath('data.section_labels.call', 'Appelez-nous');
        $this->assertSame(['stay', 'during', 'medicines', 'home', 'follow_up', 'call'], array_column($response->json('data.blocks'), 'key'));
        $this->assertSame(['Le séjour', 'Pendant le séjour', 'Ses médicaments', 'À la maison', 'Son contrôle', 'Appelez-nous'], array_column($response->json('data.blocks'), 'title'));
        $this->assertSame(['S’il vomit.', 'Clinique des Deux Rives, 032 555 12 34'], $response->json('data.blocks.5.lines'));
    }

    public function test_les_medicaments_sont_recopies_de_l_ordonnance_signee_sans_passer_par_l_ia(): void
    {
        $this->prescription('2026-11-09', 'Metacam 2,5 mg', '1 comprimé par jour avec le repas', 'pendant 5 jours');
        // Pas recopiées : une ordonnance d'avant l'admission, une ordonnance close
        $this->prescription('2026-11-01', 'Ancien produit', '1 par jour', '7 jours');
        $this->prescription('2026-11-08', 'Produit arrêté', '1 par jour', '7 jours', StatutOrdonnance::Cloturee);

        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonCount(1, 'data.copied.medicines')
            ->assertJsonPath('data.copied.medicines.0.product', 'Metacam 2,5 mg')
            ->assertJsonPath('data.copied.medicines.0.posology', '1 comprimé par jour avec le repas')
            ->assertJsonPath('data.copied.medicines.0.duration', 'pendant 5 jours')
            ->assertJsonPath('data.blocks.2.lines', ['Metacam 2,5 mg : 1 comprimé par jour avec le repas, pendant 5 jours']);
        // L'IA ne les a jamais reçus
        $this->assertStringNotContainsString('Metacam', json_encode($this->writer->calls[0], JSON_UNESCAPED_UNICODE));
    }

    public function test_le_controle_est_recopie_du_planning_sans_passer_par_l_ia(): void
    {
        $this->appointment('2026-11-12 09:00', StatutRendezVous::Annule);
        $this->appointment('2026-11-16 10:30');
        $this->appointment('2026-11-30 10:30');

        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.copied.follow_up.label', 'Lundi 16 novembre à 10:30')
            ->assertJsonPath('data.copied.follow_up.with', 'Dre Anna Meyer');
        $this->assertStringNotContainsString('16 novembre', json_encode($this->writer->calls[0], JSON_UNESCAPED_UNICODE));
    }

    /* ————— Relire, signer, envoyer ————— */

    public function test_le_veterinaire_corrige_la_lettre_avant_de_signer(): void
    {
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['home' => 'Collerette 10 jours.', 'call' => 'S’il vomit.']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.sections.home', 'Collerette 10 jours.')
            ->assertJsonPath('data.sections.stay', 'Bandit a été hospitalisé du jeudi 5 au lundi 9 novembre.')
            ->assertJsonPath('data.edited_sections', ['home', 'call']);
        $this->patchJson($this->uri(), ['sections' => ['conclusion' => 'x']], $this->enTetes($this->jeton))->assertUnprocessable();

        // Un nouveau brouillon garde les corrections
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.sections.home', 'Collerette 10 jours.');
    }

    public function test_signer_demande_le_droit_de_signer_les_comptes_rendus(): void
    {
        $this->sortir();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->sansDroit(DomainePermission::SignerComptesRendus)))->assertForbidden();
        app(ContexteClinique::class)->oublier();

        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.signed_by_name', 'Dre Anna Meyer');
    }

    public function test_signee_la_lettre_est_figee(): void
    {
        $this->sortir();
        $prescription = $this->prescription('2026-11-09', 'Metacam 2,5 mg', '1 comprimé par jour', '5 jours');
        $this->appointment('2026-11-16 10:30');
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertOk();

        $this->patchJson($this->uri(), ['sections' => ['home' => 'Plus tard.']], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->patchJson($this->uri(), ['home_notes' => 'Plus tard.'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/enregistrement", [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertUnprocessable();

        // Ce qui était recopié reste tel quel, même si l'ordonnance et le planning changent
        $prescription->forceFill(['statut' => StatutOrdonnance::Cloturee])->save();
        RendezVous::query()->withoutGlobalScopes()->update(['status' => StatutRendezVous::Annule]);
        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.copied.medicines.0.product', 'Metacam 2,5 mg')
            ->assertJsonPath('data.copied.follow_up.label', 'Lundi 16 novembre à 10:30')
            ->assertJsonPath('data.sections.home', 'Bandit garde sa collerette jusqu’au contrôle.');
    }

    public function test_une_lettre_vide_ne_se_signe_pas(): void
    {
        $this->sortir();
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_rien_n_est_a_la_lettre_avant_la_signature(): void
    {
        $this->prescription('2026-11-09', 'Metacam 2,5 mg', '1 comprimé par jour', '5 jours');
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.is_signed', false)
            ->assertJsonPath('data.signed_at', null)
            ->assertJsonPath('data.can_send', false);
        // Rien n'est figé ni envoyé
        $this->assertNull(DischargeLetter::query()->first()->copied);
        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertSame(0, MessageClientele::query()->count());
    }

    public function test_signee_la_lettre_part_au_proprietaire_une_seule_fois(): void
    {
        $this->sortir();
        $this->prescription('2026-11-09', 'Metacam 2,5 mg', '1 comprimé par jour', '5 jours');
        $this->appointment('2026-11-16 10:30');
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertJsonPath('data.can_send', true);

        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.sent_at', fn ($at) => is_string($at) && $at !== '')
            ->assertJsonPath('data.can_send', false);
        $message = MessageClientele::query()->sole();
        $this->assertSame($this->patient->client->user_id, $message->user_id);
        $this->assertStringStartsWith('Lettre de sortie de Bandit, relue et signée par Dre Anna Meyer :', $message->texte);
        $this->assertStringContainsString("Ses médicaments\n• Metacam 2,5 mg : 1 comprimé par jour, 5 jours", $message->texte);
        $this->assertStringContainsString("Son contrôle\nLundi 16 novembre à 10:30, Dre Anna Meyer", $message->texte);
        $this->assertStringContainsString("Appelez-nous\nClinique des Deux Rives, 032 555 12 34", $message->texte);
        Mail::assertSent(ReponseClinique::class);

        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertSame(1, MessageClientele::query()->count());
    }

    public function test_sans_compte_le_proprietaire_ne_recoit_pas_la_lettre(): void
    {
        $this->sortir();
        $this->patient->client->forceFill(['user_id' => null])->save();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))
            ->assertJsonPath('data.owner_has_account', false)
            ->assertJsonPath('data.can_send', false);
        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_la_lettre_se_signe_une_fois_l_animal_sorti(): void
    {
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.is_signable', false);
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('letter');

        // Sortie, puis l'ordonnance de la maison, faite juste après : elle est dans la lettre signée
        $this->sortir();
        $this->prescription('2026-11-09', 'Metacam 2,5 mg', '1 comprimé par jour', '5 jours');
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.copied.medicines.0.product', 'Metacam 2,5 mg');
    }

    public function test_le_controle_est_un_rendez_vous_de_controle(): void
    {
        $this->appointment('2026-11-12 09:00', type: TypeRendezVous::Vaccin);
        $this->appointment('2026-11-16 10:30');
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.copied.follow_up.label', 'Lundi 16 novembre à 10:30');
    }

    public function test_une_ordonnance_du_matin_d_avant_l_admission_n_est_pas_recopiee(): void
    {
        $this->sejour->forceFill(['admis_le' => now()->subHour()])->save();
        $this->travel(-3)->hours();
        $this->prescription('2026-11-09', 'Ordonnance de la consultation', '1 par jour', '7 jours');
        $this->travelBack();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 14:00', 'Europe/Zurich'));
        $this->prescription('2026-11-09', 'Metacam 2,5 mg', '1 comprimé par jour', '5 jours');
        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonCount(1, 'data.copied.medicines')
            ->assertJsonPath('data.copied.medicines.0.product', 'Metacam 2,5 mg');
    }

    public function test_des_consignes_changees_pendant_une_redaction_en_lancent_une_nouvelle(): void
    {
        DischargeLetters::ensure($this->sejour);
        DischargeLetter::query()->update(['status' => 'writing']);
        $this->notes("collerette jusqu'au contrôle");
        $this->assertTrue(DischargeLetter::query()->first()->is_redraft_pending);

        (new WriteDischargeLetter($this->sejour->id))->handle($this->writer, app(DischargeLetterSources::class), app(DischargeLetters::class));
        $this->assertCount(2, $this->writer->calls);
        $this->assertSame(["collerette jusqu'au contrôle"], collect($this->writer->calls[1])->where('kind', 'note')->pluck('text')->values()->all());
    }

    public function test_une_lettre_ecrite_a_la_main_apres_un_echec_est_a_relire(): void
    {
        $this->writer->error = DischargeLetterWriterFake::refusal();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['home' => 'Repos 4 semaines.']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.error', null);
    }

    public function test_sans_e_mail_si_le_proprietaire_l_a_coupe(): void
    {
        $this->sortir();
        PreferencesProprietaire::query()->create(['user_id' => $this->patient->client->user_id, 'reponses_clinique' => false]);
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(1, MessageClientele::query()->count());
        Mail::assertNotSent(ReponseClinique::class);
    }

    /* ————— 2e vérification (09.11.2026) ————— */

    public function test_une_lettre_signee_apres_le_controle_ne_l_annonce_pas(): void
    {
        $this->appointment('2026-11-10 09:00');
        $this->appointment('2026-11-16 10:30');
        $this->sortir();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00', 'Europe/Zurich'));
        $this->notes();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertJsonPath('data.copied.follow_up.label', 'Lundi 16 novembre à 10:30');
    }

    public function test_un_traitement_prevu_apres_la_sortie_n_est_pas_une_source(): void
    {
        $this->sejour->treatments()->create([
            'label' => 'Amoxicilline', 'dose' => '20 mg/kg PO', 'times' => [],
            'starts_at' => CarbonImmutable::parse('2026-11-10 08:00', 'Europe/Zurich')->utc(),
        ]);
        $this->sortir();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->assertSame(['Ringer lactate, 5 ml/kg/h IV, du 5 novembre au 7 novembre'], collect($this->writer->calls[0])->where('kind', 'treatment')->pluck('text')->values()->all());
    }

    public function test_une_dictee_close_par_la_signature_ne_se_relance_pas(): void
    {
        $this->sortir();
        $id = $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        EnregistrementConsultation::withoutGlobalScopes()->whereKey($id)->update(['statut' => StatutEnregistrement::Valide->value]);
        $this->postJson("/api/v1/enregistrements/{$id}/relancer", [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertSame(StatutEnregistrement::Valide, EnregistrementConsultation::withoutGlobalScopes()->find($id)->statut);
    }

    /* ————— À terminer ————— */

    public function test_a_terminer_compte_la_lettre_une_fois_l_animal_sorti_tant_qu_elle_n_est_pas_signee(): void
    {
        $lines = fn () => collect($this->getJson('/api/v1/unfinished-work', $this->enTetes($this->jeton))->json('data.groups'))->firstWhere('kind', 'discharge_letter')['lines'] ?? [];

        // Encore hospitalisé : pas de lettre à terminer
        $this->assertSame([], $lines());
        $this->sejour->forceFill(['sorti_le' => now()])->save();
        $this->assertSame([['type' => 'discharge_letter', 'id' => $this->sejour->id]], array_column($lines(), 'target'));
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->assertCount(1, $lines());
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton));
        $this->assertSame([], $lines());
    }

    public function test_a_terminer_ne_compte_pas_les_sorties_d_avant_la_lettre(): void
    {
        $this->sejour->forceFill(['admis_le' => CarbonImmutable::parse('2026-10-20 09:00', 'Europe/Zurich'), 'sorti_le' => CarbonImmutable::parse('2026-11-08 17:00', 'Europe/Zurich')])->save();
        $groups = collect($this->getJson('/api/v1/unfinished-work', $this->enTetes($this->jeton))->json('data.groups'));
        $this->assertNull($groups->firstWhere('kind', 'discharge_letter'));
    }

    /* ————— Budget, plafond, droits ————— */

    public function test_les_redactions_restent_dans_le_budget_et_le_plafond(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        }
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('letter');
        $this->assertCount(3, $this->writer->calls);
        $this->assertSame(3, CompteurMensuel::valeur('anthropic:redactions'));

        DischargeLetter::query()->update(['writings' => 0]);
        config(['services.anthropic.budget_usd' => 0]);
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('letter');
        $this->assertCount(3, $this->writer->calls);
    }

    public function test_un_refus_du_redacteur_laisse_la_lettre_en_echec_relancable(): void
    {
        $this->writer->error = DischargeLetterWriterFake::refusal();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.status', 'failed');
        $this->writer->error = null;
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.status', 'draft');
    }

    public function test_pas_de_signature_pendant_une_dictee(): void
    {
        $this->sortir();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/enregistrement", [], $this->enTetes($this->jeton))->assertCreated();
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('letter');
    }

    public function test_signer_clot_la_dictee(): void
    {
        $this->sortir();
        $id = $this->dicter();
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(StatutEnregistrement::Valide, EnregistrementConsultation::withoutGlobalScopes()->find($id)->statut);
    }

    public function test_une_dictee_prete_pendant_une_redaction_en_lance_une_nouvelle(): void
    {
        DischargeLetters::ensure($this->sejour);
        DischargeLetter::query()->update(['status' => 'writing']);
        $this->dicter();
        $this->assertTrue(DischargeLetter::query()->first()->is_redraft_pending);

        (new WriteDischargeLetter($this->sejour->id))->handle($this->writer, app(DischargeLetterSources::class), app(DischargeLetters::class));
        $this->assertCount(2, $this->writer->calls);
        $this->assertFalse(DischargeLetter::query()->first()->is_redraft_pending);
    }

    public function test_l_audio_d_une_dictee_de_lettre_demande_le_droit_patients(): void
    {
        $id = $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', TranscripteurFactice::ENTETE.'AA'),
        ], [...$this->enTetes($this->sansDroit(DomainePermission::Patients)), 'Accept' => 'application/json'])->assertForbidden();
    }

    public function test_une_clinique_soeur_ne_touche_pas_a_la_dictee(): void
    {
        $id = $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/enregistrement", [], $this->enTetes($this->jeton))->json('data.id');
        app(ContexteClinique::class)->oublier();
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $autre] = $this->employeAvecJeton($soeur);
        $entetes = [...$this->enTetes($autre), 'Accept' => 'application/json'];

        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $entetes)->assertNotFound();
        $this->deleteJson("/api/v1/enregistrements/{$id}", [], $entetes)->assertNotFound();
        $this->assertSame(StatutEnregistrement::Enregistrement, EnregistrementConsultation::query()->findOrFail($id)->statut);
    }

    public function test_une_autre_organisation_ne_voit_rien(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        app(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());
        $this->getJson($this->uri(), $this->enTetes($autre))->assertNotFound();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($autre))->assertNotFound();
        $this->postJson($this->uri('/sign'), [], $this->enTetes($autre))->assertNotFound();
        $this->postJson($this->uri('/send'), [], $this->enTetes($autre))->assertNotFound();
    }
}
