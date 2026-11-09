<?php

namespace Tests\Feature\Patients;

use App\Auth\ContexteClinique;
use App\Enums\Espece;
use App\Enums\StatutAnalyse;
use App\Enums\StatutIntervention;
use App\Mail\RecordShareLink;
use App\Models\Analyse;
use App\Models\ClicheDicom;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\DischargeLetter;
use App\Models\Document;
use App\Models\ExamenImagerie;
use App\Models\Intervention;
use App\Models\MessageClientele;
use App\Models\Ordonnance;
use App\Models\PanelLabo;
use App\Models\Patient;
use App\Models\RecordShare;
use App\Models\RecordShareEvent;
use App\Models\RendezVous;
use App\Models\Salle;
use App\Models\Sejour;
use App\Models\StaySheet;
use App\Models\SurgeryReport;
use App\Models\User;
use App\Models\Vaccination;
use App\Models\Visite;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Partager le dossier médical (demande de Jim, 09.11.2026), étape 1 : les
 * pièces d'une période (6 mois par défaut côté écran, ou tout), chacune
 * sous une forme imprimable, un lien provisoire de 7, 30 ou 90 jours
 * envoyé au confrère ou à copier, la page du lien sans compte avec
 * seulement les pièces choisies, « Tout télécharger » limité (5 par lien,
 * 50 par clinique et par mois), les ouvertures et téléchargements notés,
 * le lien coupé à tout moment, le propriétaire prévenu.
 */
class RecordShareTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    private RendezVous $consultation;

    private ExamenImagerie $imaging;

    private Document $document;

    private Ordonnance $prescription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 14:00', 'Europe/Zurich'));
        Storage::fake('prive');
        Mail::fake();

        $this->clinique = Clinique::factory()->create(['nom' => 'Clinique des Deux Rives', 'tel' => '021 601 42 42']);
        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anna Meyer']);
        $organisationId = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisationId, 'user_id' => User::factory()->create()->id]);
        $this->patient = Patient::factory()->for($client)->create(['nom' => 'Rocky', 'espece' => Espece::Chien, 'race' => 'Berger allemand']);

        // Une consultation au compte rendu signé, une autre au compte rendu pas signé
        $this->consultation = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'reason' => 'Boiterie', 'starts_at' => now()->subDays(7), 'ends_at' => now()->subDays(7)->addMinutes(20)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $this->consultation->id, 'signe_le' => now()->subDays(7)]);
        $unsigned = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'reason' => 'Pas signé', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addMinutes(20)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $unsigned->id, 'signe_le' => null]);

        // Une analyse validée, une en cours
        $lab = Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutAnalyse::Valide, 'preleve_le' => now()->subDays(6)]);
        $panelLabo = PanelLabo::withoutGlobalScopes()->create(['organisation_id' => $organisationId, 'code' => 'hep', 'nom' => 'Bilan hépatique', 'tube' => 'sec']);
        $panel = $lab->panels()->create(['panel_labo_id' => $panelLabo->id, 'code' => 'hep', 'nom' => 'Bilan hépatique', 'tube' => 'sec']);
        $lab->resultats()->create(['analyse_panel_id' => $panel->id, 'position' => 1, 'parametre' => 'ALAT', 'unite' => 'U/l', 'borne_basse' => 10, 'borne_haute' => 100, 'valeur' => 112, 'drapeau' => 'H']);
        Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutAnalyse::Cours]);

        // Une radiographie interprétée, avec son cliché
        $this->imaging = ExamenImagerie::factory()->signe()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'libelle' => 'Radiographies du genou gauche', 'realise_le' => now()->subDays(6)]);
        Storage::disk('prive')->put('radios/1.dcm', 'DICM-cliche');
        ClicheDicom::query()->create(['examen_imagerie_id' => $this->imaging->id, 'sop_instance_uid' => '1.2.3', 'study_instance_uid' => '1.2', 'series_instance_uid' => '1.2.9', 'nom_fichier' => 'genou.dcm', 'chemin' => 'radios/1.dcm', 'taille_octets' => 11]);

        // Un document ajouté au dossier, avec son fichier ; un vieux, hors des 6 mois
        Storage::disk('prive')->put('documents/bilan.pdf', '%PDF-1.4 bilan');
        $this->document = Document::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Bilan de la clinique du Lac', 'chemin' => 'documents/bilan.pdf', 'etabli_le' => now()->subMonths(2)]);
        Document::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Vieux document', 'chemin' => 'documents/bilan.pdf', 'etabli_le' => now()->subYears(2)]);

        $this->prescription = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'organisation_id' => $organisationId, 'patient_id' => $this->patient->id, 'employe_id' => $employe->id, 'signee_le' => now()->subDays(7)]);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'injectee_le' => now()->subMonths(8)]);

        // Un séjour : un rapport signé, sa lettre de sortie signée ; une intervention au rapport validé
        $stay = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'admis_le' => now()->subDays(5), 'sorti_le' => now()->subDays(2), 'motif' => 'Surveillance']);
        StaySheet::query()->create(['sejour_id' => $stay->id, 'day' => now()->subDays(4)->toDateString(), 'opened_at' => now(), 'report_status' => StaySheet::REPORT_SIGNED, 'report_text' => 'Rocky est calme.', 'report_signed_at' => now(), 'report_signed_by_name' => 'Dre Anna Meyer']);
        DischargeLetter::query()->create(['sejour_id' => $stay->id, 'status' => 'signed', 'sections' => ['stay' => 'Rocky a été hospitalisé.'], 'copied' => ['medicines' => [], 'follow_up' => null, 'clinic' => ['name' => 'Clinique des Deux Rives', 'phone' => null]], 'signed_at' => now(), 'signed_by_name' => 'Dre Anna Meyer']);
        $salle = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id]);
        $intervention = Intervention::query()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'salle_id' => $salle->id, 'salle_nom' => 'Salle 1', 'chirurgien_id' => $employe->id, 'procedure' => 'Arthrotomie', 'prevu_le' => now()->subDays(5), 'duree_minutes' => 60, 'statut' => StatutIntervention::Fini, 'protocole_anesthesique' => []]);
        SurgeryReport::query()->create(['intervention_id' => $intervention->id, 'status' => 'validated', 'sections' => ['technique' => 'Arthrotomie médiale.'], 'validated_at' => now(), 'validated_by_name' => 'Dre Anna Meyer']);

        app(ContexteClinique::class)->oublier();
    }

    private function items(array $query = []): array
    {
        return $this->getJson("/api/v1/patients/{$this->patient->id}/share-items?".http_build_query($query), $this->enTetes($this->jeton))->assertOk()->json('data');
    }

    /** @return list<string> les clés type:id d'une liste de groupes */
    private function keys(array $groups): array
    {
        return collect($groups)->flatMap(fn (array $g) => array_map(fn (array $i) => $i['type'].':'.$i['id'], $g['items']))->all();
    }

    /** @return array{0: string, 1: RecordShare} */
    private function share(array $items, int $days = 30, ?string $email = 'l.brunner@crv-lausanne.ch'): array
    {
        $response = $this->postJson("/api/v1/patients/{$this->patient->id}/shares", ['items' => $items, 'days' => $days, 'recipient_email' => $email], $this->enTetes($this->jeton))->assertCreated();
        $token = basename((string) $response->json('data.url'));

        return [$token, RecordShare::withoutGlobalScopes()->findOrFail($response->json('data.id'))];
    }

    public function test_les_pieces_d_une_periode_par_groupe(): void
    {
        $groups = $this->items(['from' => '2026-05-09', 'to' => '2026-11-09']);
        $this->assertSame(['consultation', 'lab', 'imaging', 'surgery', 'stay_report', 'discharge_letter', 'prescription', 'document'], array_column($groups, 'key'));
        $this->assertSame('Consultations et comptes rendus', $groups[0]['label']);
        // Rien de pas signé ni de pas validé ; rien d'avant la période
        $keys = $this->keys($groups);
        $this->assertContains('consultation:'.$this->consultation->id, $keys);
        $this->assertCount(1, array_filter($keys, fn ($k) => str_starts_with($k, 'consultation:')));
        $this->assertCount(1, array_filter($keys, fn ($k) => str_starts_with($k, 'lab:')));
        $this->assertNotContains('vaccines:'.$this->patient->id, $keys);
        $this->assertSame(['document:'.$this->document->id], array_values(array_filter($keys, fn ($k) => str_starts_with($k, 'document:'))));
    }

    public function test_tout_le_dossier_sans_periode(): void
    {
        $keys = $this->keys($this->items());
        $this->assertContains('vaccines:'.$this->patient->id, $keys);
        $this->assertCount(2, array_filter($keys, fn ($k) => str_starts_with($k, 'document:')));
    }

    public function test_chaque_piece_sous_sa_forme_imprimable(): void
    {
        $uri = fn (string $type, int $id) => "/api/v1/patients/{$this->patient->id}/share-items/{$type}/{$id}";
        $this->getJson($uri('consultation', $this->consultation->id), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.title', 'Consultation : Boiterie')
            ->assertJsonPath('data.clinic', 'Clinique des Deux Rives')
            ->assertJsonPath('data.sections.0.label', 'Motif et anamnèse');
        $lab = Analyse::withoutGlobalScopes()->where('statut', StatutAnalyse::Valide->value)->firstOrFail();
        $rows = $this->getJson($uri('lab', $lab->id), $this->enTetes($this->jeton))
            ->assertJsonPath('data.table.columns', ['Paramètre', 'Résultat', 'Unité', 'Normes', ''])
            ->json('data.table.rows');
        $this->assertContains(['ALAT', '112', 'U/l', '10 à 100', 'Haut'], $rows);
        $this->getJson($uri('imaging', $this->imaging->id), $this->enTetes($this->jeton))
            ->assertJsonPath('data.files.0.mime', 'application/dicom');
        $this->getJson($uri('vaccines', $this->patient->id), $this->enTetes($this->jeton))->assertJsonPath('data.title', 'Carnet de vaccination');
        $this->getJson($uri('prescription', $this->prescription->id), $this->enTetes($this->jeton))->assertJsonPath('data.table.columns.0', 'Médicament');
        // Une pièce pas signée ne se lit pas
        $unsigned = RendezVous::withoutGlobalScopes()->where('reason', 'Pas signé')->firstOrFail();
        $this->getJson($uri('consultation', $unsigned->id), $this->enTetes($this->jeton))->assertNotFound();
        $this->get("/api/v1/patients/{$this->patient->id}/share-items/document/{$this->document->id}/files/0", $this->enTetes($this->jeton))->assertOk();
    }

    public function test_un_lien_de_7_30_ou_90_jours_envoye_au_confrere(): void
    {
        $this->postJson("/api/v1/patients/{$this->patient->id}/shares", ['items' => [['type' => 'document', 'id' => $this->document->id]], 'days' => 15], $this->enTetes($this->jeton))->assertUnprocessable();
        [$token, $share] = $this->share([['type' => 'document', 'id' => $this->document->id]], 90);
        $this->assertSame(48, strlen($token));
        $this->assertSame(now()->addDays(90)->toDateString(), $share->expires_at->toDateString());
        $this->assertNotSame($token, $share->token_hash);
        Mail::assertSent(RecordShareLink::class, fn (RecordShareLink $m) => $m->hasTo('l.brunner@crv-lausanne.ch') && str_contains($m->url, '/share/'.$token));
    }

    public function test_un_lien_sans_destinataire_se_copie(): void
    {
        $response = $this->postJson("/api/v1/patients/{$this->patient->id}/shares", ['items' => [['type' => 'document', 'id' => $this->document->id]], 'days' => 30], $this->enTetes($this->jeton))->assertCreated();
        $this->assertStringContainsString('/share/', (string) $response->json('data.url'));
        Mail::assertNotSent(RecordShareLink::class);
    }

    public function test_une_piece_d_un_autre_animal_ne_se_partage_pas(): void
    {
        $other = Document::factory()->create(['patient_id' => Patient::factory()->create(['organisation_id' => $this->patient->organisation_id])->id, 'chemin' => 'documents/bilan.pdf']);
        $this->postJson("/api/v1/patients/{$this->patient->id}/shares", ['items' => [['type' => 'document', 'id' => $other->id]], 'days' => 30], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_la_page_du_lien_sans_compte_ne_montre_que_les_pieces_choisies(): void
    {
        [$token] = $this->share([['type' => 'consultation', 'id' => $this->consultation->id], ['type' => 'imaging', 'id' => $this->imaging->id]]);
        $this->getJson("/api/v1/public/record-shares/{$token}")
            ->assertOk()
            ->assertJsonPath('data.patient.name', 'Rocky')
            ->assertJsonPath('data.clinic.name', 'Clinique des Deux Rives')
            ->assertJsonPath('data.shared_by', 'Dre Anna Meyer')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.type', 'imaging');
        $this->getJson("/api/v1/public/record-shares/{$token}/items/consultation/{$this->consultation->id}")->assertOk()->assertJsonPath('data.sections.0.label', 'Motif et anamnèse');
        $this->get("/api/v1/public/record-shares/{$token}/items/imaging/{$this->imaging->id}/files/0")->assertOk()->assertHeader('Content-Type', 'application/dicom');
        // Rien d'autre du dossier
        $this->getJson("/api/v1/public/record-shares/{$token}/items/document/{$this->document->id}")->assertNotFound();
        $this->get("/api/v1/public/record-shares/{$token}/items/document/{$this->document->id}/files/0")->assertNotFound();
        $this->getJson("/api/v1/public/record-shares/{$token}/items/prescription/{$this->prescription->id}")->assertNotFound();
    }

    public function test_tout_telecharger_cinq_fois_par_lien(): void
    {
        [$token] = $this->share([['type' => 'document', 'id' => $this->document->id]]);
        foreach (range(4, 0) as $left) {
            $this->postJson("/api/v1/public/record-shares/{$token}/downloads")->assertOk()->assertJsonPath('data.downloads_left', $left);
        }
        $this->postJson("/api/v1/public/record-shares/{$token}/downloads")->assertStatus(429);
        // Ouvrir une pièce reste libre
        $this->getJson("/api/v1/public/record-shares/{$token}/items/document/{$this->document->id}")->assertOk();
    }

    public function test_cinquante_telechargements_complets_par_clinique_et_par_mois(): void
    {
        [$token, $share] = $this->share([['type' => 'document', 'id' => $this->document->id]]);
        foreach (range(1, 50) as $i) {
            RecordShareEvent::query()->create(['record_share_id' => $share->id, 'kind' => 'downloaded', 'at' => now()->subDay()]);
        }
        $this->getJson("/api/v1/public/record-shares/{$token}")->assertJsonPath('data.downloads_left', 0);
        $this->postJson("/api/v1/public/record-shares/{$token}/downloads")->assertStatus(429);
        // Le mois suivant, de nouveau
        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'Europe/Zurich'));
        $this->postJson("/api/v1/public/record-shares/{$token}/downloads")->assertOk();
    }

    public function test_la_clinique_voit_quand_le_lien_a_ete_ouvert_et_telecharge(): void
    {
        [$token] = $this->share([['type' => 'document', 'id' => $this->document->id]]);
        $this->getJson("/api/v1/public/record-shares/{$token}")->assertOk();
        $this->postJson("/api/v1/public/record-shares/{$token}/downloads")->assertOk();
        $this->getJson("/api/v1/patients/{$this->patient->id}/shares", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.recipient_email', 'l.brunner@crv-lausanne.ch')
            ->assertJsonPath('data.0.opened_at', now()->toIso8601String())
            ->assertJsonPath('data.0.downloaded_at.0', now()->toIso8601String())
            ->assertJsonPath('data.0.full_downloads', 1);
    }

    public function test_un_lien_se_coupe_a_tout_moment(): void
    {
        [$token, $share] = $this->share([['type' => 'document', 'id' => $this->document->id]]);
        $this->postJson("/api/v1/record-shares/{$share->id}/revoke", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.revoked_by_name', 'Dre Anna Meyer');
        $this->getJson("/api/v1/public/record-shares/{$token}")->assertNotFound();
        $this->getJson("/api/v1/public/record-shares/{$token}/items/document/{$this->document->id}")->assertNotFound();
    }

    public function test_un_lien_expire_ne_s_ouvre_plus(): void
    {
        [$token] = $this->share([['type' => 'document', 'id' => $this->document->id]], 7);
        $this->travel(8)->days();
        $this->getJson("/api/v1/public/record-shares/{$token}")->assertNotFound();
    }

    public function test_le_proprietaire_est_prevenu_avec_qui_le_dossier_est_partage(): void
    {
        $this->share([['type' => 'document', 'id' => $this->document->id]]);
        $message = MessageClientele::query()->sole();
        $this->assertSame($this->patient->client->user_id, $message->user_id);
        $this->assertSame('Le dossier de Rocky a été partagé par Dre Anna Meyer avec l.brunner@crv-lausanne.ch, jusqu’au 9 décembre 2026.', $message->texte);
    }

    public function test_un_jeton_inconnu_ne_dit_rien(): void
    {
        $this->getJson('/api/v1/public/record-shares/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_une_autre_clinique_ne_voit_ni_ne_coupe_les_liens(): void
    {
        [, $share] = $this->share([['type' => 'document', 'id' => $this->document->id]]);
        app(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());
        $this->getJson("/api/v1/patients/{$this->patient->id}/shares", $this->enTetes($autre))->assertNotFound();
        $this->postJson("/api/v1/record-shares/{$share->id}/revoke", [], $this->enTetes($autre))->assertNotFound();
        $this->getJson("/api/v1/patients/{$this->patient->id}/share-items", $this->enTetes($autre))->assertNotFound();
    }

    /* ————— 1re vérification (09.11.2026) ————— */

    public function test_les_notes_des_visites_partent_seulement_si_le_veterinaire_le_choisit(): void
    {
        $visit = Visite::query()->create(['patient_id' => $this->patient->id, 'visite_le' => now()->subDays(20), 'motif' => 'Otite', 'note' => 'Propriétaire informé du protocole.']);
        $item = [['type' => 'visit', 'id' => $visit->id]];
        [$without] = $this->share($item);
        $this->getJson("/api/v1/public/record-shares/{$without}/items/visit/{$visit->id}")->assertOk()->assertJsonPath('data.sections', [['label' => 'Motif', 'text' => 'Otite']]);

        $response = $this->postJson("/api/v1/patients/{$this->patient->id}/shares", ['items' => $item, 'days' => 30, 'visit_notes' => true], $this->enTetes($this->jeton))->assertCreated()->assertJsonPath('data.include_visit_notes', true);
        $with = basename((string) $response->json('data.url'));
        $this->getJson("/api/v1/public/record-shares/{$with}/items/visit/{$visit->id}")->assertJsonPath('data.sections.1', ['label' => 'Notes', 'text' => 'Propriétaire informé du protocole.']);
        // L'export de la clinique : au même choix
        $this->getJson("/api/v1/patients/{$this->patient->id}/share-items/visit/{$visit->id}", $this->enTetes($this->jeton))->assertJsonCount(1, 'data.sections');
        $this->getJson("/api/v1/patients/{$this->patient->id}/share-items/visit/{$visit->id}?notes=1", $this->enTetes($this->jeton))->assertJsonCount(2, 'data.sections');
    }

    public function test_une_clinique_soeur_voit_et_coupe_les_liens_du_dossier(): void
    {
        [, $share] = $this->share([['type' => 'document', 'id' => $this->document->id]]);
        app(ContexteClinique::class)->oublier();
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $autre] = $this->employeAvecJeton($soeur);
        $this->getJson("/api/v1/patients/{$this->patient->id}/shares", $this->enTetes($autre))
            ->assertOk()
            ->assertJsonPath('data.0.id', $share->id)
            ->assertJsonPath('data.0.clinic', 'Clinique des Deux Rives');
        $this->postJson("/api/v1/record-shares/{$share->id}/revoke", [], $this->enTetes($autre))->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_ce_qui_s_ajoute_apres_le_partage_ne_rejoint_pas_le_lien(): void
    {
        $surgery = SurgeryReport::query()->firstOrFail();
        [$token] = $this->share([['type' => 'vaccines', 'id' => $this->patient->id], ['type' => 'surgery', 'id' => $surgery->id]]);
        $this->travel(1)->days();
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'injectee_le' => now()]);
        $surgery->complements()->create(['text' => 'Ajouté après le partage.', 'by_name' => 'Dre Anna Meyer']);
        $this->getJson("/api/v1/public/record-shares/{$token}/items/vaccines/{$this->patient->id}")->assertJsonCount(1, 'data.table.rows');
        // La liste aussi, telle qu'au partage (2e vérification)
        $vaccines = collect($this->getJson("/api/v1/public/record-shares/{$token}")->json('data.items'))->firstWhere('type', 'vaccines');
        $this->assertSame('1 vaccination', $vaccines['subtitle']);
        $sections = $this->getJson("/api/v1/public/record-shares/{$token}/items/surgery/{$surgery->id}")->json('data.sections');
        $this->assertNotContains('Ajouté après le partage.', array_column($sections, 'text'));
    }

    public function test_le_mois_de_la_clinique_se_compte_a_son_heure(): void
    {
        [$token, $share] = $this->share([['type' => 'document', 'id' => $this->document->id]]);
        // Le 30 novembre à 23:30 à Zurich (22:30 UTC) : novembre, pas décembre
        foreach (range(1, 50) as $i) {
            RecordShareEvent::query()->create(['record_share_id' => $share->id, 'kind' => 'downloaded', 'at' => CarbonImmutable::parse('2026-11-30 23:30', 'Europe/Zurich')->utc()]);
        }
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30', 'Europe/Zurich'));
        $share->forceFill(['full_downloads' => 0])->save();
        $this->postJson("/api/v1/public/record-shares/{$token}/downloads")->assertOk();
    }

    public function test_la_fin_du_lien_se_dit_a_l_heure_de_la_clinique(): void
    {
        // Le 10 novembre à 00:30 à Zurich (23:30 UTC le 9) : jusqu'au 10 décembre
        $this->travelTo(CarbonImmutable::parse('2026-11-10 00:30', 'Europe/Zurich'));
        $this->share([['type' => 'document', 'id' => $this->document->id]]);
        $this->assertStringEndsWith('jusqu’au 10 décembre 2026.', MessageClientele::query()->sole()->texte);
        Mail::assertSent(RecordShareLink::class, fn (RecordShareLink $m) => str_contains($m->render(), '10 décembre 2026'));
    }

    public function test_un_rapport_d_hospitalisation_pas_signe_ne_part_pas_et_les_autres_se_lisent(): void
    {
        $stay = Sejour::withoutGlobalScopes()->firstOrFail();
        $draft = StaySheet::query()->create(['sejour_id' => $stay->id, 'day' => now()->subDays(3)->toDateString(), 'opened_at' => now(), 'report_status' => StaySheet::REPORT_TO_REVIEW, 'report_text' => 'Pas encore relu.']);
        $keys = $this->keys($this->items());
        $this->assertNotContains('stay_report:'.$draft->id, $keys);
        $uri = fn (string $type, int $id) => "/api/v1/patients/{$this->patient->id}/share-items/{$type}/{$id}";
        $this->getJson($uri('stay_report', $draft->id), $this->enTetes($this->jeton))->assertNotFound();
        $signed = StaySheet::query()->where('report_status', StaySheet::REPORT_SIGNED)->firstOrFail();
        $this->getJson($uri('stay_report', $signed->id), $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.sections.1.text', 'Rocky est calme.');
        $this->getJson($uri('discharge_letter', DischargeLetter::query()->firstOrFail()->id), $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.sections.0.label', 'Le séjour');
        $this->getJson($uri('surgery', SurgeryReport::query()->firstOrFail()->id), $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.sections.0', ['label' => 'Technique et constatations', 'text' => 'Arthrotomie médiale.']);
    }

    /* ————— 2e vérification (09.11.2026) ————— */

    public function test_une_periode_inversee_est_refusee(): void
    {
        $this->postJson("/api/v1/patients/{$this->patient->id}/shares", ['items' => [['type' => 'document', 'id' => $this->document->id]], 'days' => 30, 'from' => '2026-11-09', 'to' => '2026-05-09'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_la_page_du_lien_recoit_le_fuseau_de_la_clinique(): void
    {
        [$token] = $this->share([['type' => 'document', 'id' => $this->document->id]]);
        $this->getJson("/api/v1/public/record-shares/{$token}")->assertJsonPath('data.timezone', 'Europe/Zurich');
    }

    /* ————— 3e vérification (09.11.2026) ————— */

    public function test_sans_e_mail_le_proprietaire_lit_par_un_lien_provisoire(): void
    {
        $this->share([['type' => 'document', 'id' => $this->document->id]], 30, null);
        $this->assertSame('Le dossier de Rocky a été partagé par Dre Anna Meyer par un lien provisoire, jusqu’au 9 décembre 2026.', MessageClientele::query()->sole()->texte);
    }

    public function test_la_liste_des_pieces_refuse_une_periode_inversee(): void
    {
        $this->getJson("/api/v1/patients/{$this->patient->id}/share-items?from=2026-11-09&to=2026-05-09", $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
