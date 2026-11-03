<?php

namespace Tests\Feature\Hospitalisation;

use App\Enums\DomainePermission;
use App\Enums\Espece;
use App\Events\StayUpdated;
use App\Jobs\DraftDailyReport;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\MessageClientele;
use App\Models\MouvementStock;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StaySheet;
use App\Models\StayTreatment;
use App\Models\Stock;
use App\Models\User;
use App\Services\CompteurMensuel;
use App\Services\Hospitalisation\DailyReportWriter;
use App\Services\Hospitalisation\StaySheets;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\DailyReportWriterFake;
use Tests\TestCase;

/**
 * Hospitalisation, suite de la vérification et décisions de Jim
 * (03.11.2026) : note tardive sans limite, médicaments à la dose (stock
 * et facture), rapport au propriétaire de l'admission, la nuit, le
 * rapport corrigeable et écrit à la main, le changement d'heure, signer
 * le texte enregistré, et les points faibles. Il est 14:05 le mardi
 * 3 novembre 2026 à la clinique.
 */
class RoundFollowUpTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $vet;

    private string $jeton;

    private string $jetonAsv;

    private Patient $chien;

    private Client $proprietaire;

    private DailyReportWriterFake $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:05', 'Europe/Zurich'));
        config(['services.anthropic.key' => 'cle-de-test', 'services.anthropic.budget_usd' => null]);
        $this->app->instance(DailyReportWriter::class, $this->writer = new DailyReportWriterFake);

        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        ['employe' => $this->vet, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Rachel Solal']);
        ['jeton' => $this->jetonAsv] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Muller']);

        $this->proprietaire = Client::factory()->create(['organisation_id' => $this->organisationId(), 'user_id' => User::factory()->create()->id]);
        $this->chien = Patient::factory()->for($this->proprietaire)->create(['espece' => Espece::Chien, 'nom' => 'Tao']);
    }

    private function organisationId(): int
    {
        return $this->clinique->societe->organisation_id;
    }

    private function sejour(string $admis = '2026-11-01 09:15', array $plus = []): Sejour
    {
        return Sejour::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->chien->id,
            'client_id' => $this->proprietaire->id,
            'box' => 2,
            'motif' => 'Insuffisance rénale décompensée',
            'statut' => 'surv',
            'admis_le' => CarbonImmutable::parse($admis, 'Europe/Zurich')->utc(),
            ...$plus,
        ]);
    }

    private function url(Sejour $sejour, string $suite = ''): string
    {
        return "/api/v1/hospitalisation/sejours/{$sejour->id}{$suite}";
    }

    private function ronde(string $du, string $au, array $plus = []): array
    {
        return $this->getJson('/api/v1/hospitalisation/ronde?'.http_build_query(['from' => $du, 'to' => $au, ...$plus]), $this->enTetes($this->jeton))->assertOk()->json('data');
    }

    private function produit(array $plus = []): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->organisationId(),
            'nom' => 'Cerenia 10 mg/ml · 20 ml',
            'categorie' => 'med',
            'unite' => 'flacon',
            'prix' => 60,
            'est_stupefiant' => false,
            'dose_unit' => 'ml',
            'content_per_unit' => 20,
            ...$plus,
        ]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 3]));

        return $produit;
    }

    // ————— Note tardive : sans limite, marquée « noté après coup » —————

    public function test_une_prise_oubliee_se_note_apres_coup_avec_l_heure_reelle(): void
    {
        $sejour = $this->sejour();
        $hier = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-02', 'prevu_a' => 1320, 'acte' => 'Maropitant', 'treatment_id' => null]);
        $url = $this->url($sejour, "/soins/{$hier->id}/faire");

        // Sur une feuille close, l'heure réelle du geste est demandée
        $this->postJson($url, [], $this->enTetes($this->jetonAsv))->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->postJson($url, ['time' => 1330], $this->enTetes($this->jetonAsv))->assertOk();

        $soin = collect($this->ronde('2026-11-02', '2026-11-02')['stays'][0]['cares'])->firstWhere('id', $hier->id);
        $this->assertSame('done', $soin['status']);
        $this->assertSame('22:10', $soin['done_time']);
        $this->assertTrue($soin['is_late_note']);
        $this->assertSame('Noté après coup, le 3.11 à 14:05, par Julie Muller', $soin['late_label']);
    }

    public function test_douleur_observation_constantes_et_soin_se_notent_apres_coup(): void
    {
        $sejour = $this->sejour();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson($this->url($sejour, '/douleurs'), ['score' => 7, 'sheet_day' => '2026-11-02', 'time' => 1200], $entetes)
            ->assertCreated()->assertJsonPath('data.time', '20:00')->assertJsonPath('data.is_late_note', true);
        $this->postJson($this->url($sejour, '/observations'), ['kind' => 'eats', 'value' => 'rien', 'sheet_day' => '2026-11-02', 'time' => 120], $entetes)
            ->assertCreated()->assertJsonPath('data.day', '2026-11-03')->assertJsonPath('data.sheet_day', '2026-11-02')->assertJsonPath('data.is_late_note', true);
        $this->postJson($this->url($sejour, '/constantes'), ['sheet_day' => '2026-11-02', 'mesure_a' => 1140, 'temperature' => 38.6, 'fc' => 100, 'fr' => 24], $entetes)
            ->assertCreated()->assertJsonPath('data.by', 'Dr Rachel Solal')->assertJsonPath('data.is_late_note', true);
        $this->postJson($this->url($sejour, '/soins'), ['sheet_day' => '2026-11-02', 'prevu_a' => 900, 'acte' => 'Pansement'], $entetes)->assertCreated();

        // Une heure à venir ne se note pas
        $this->postJson($this->url($sejour, '/douleurs'), ['score' => 2, 'sheet_day' => '2026-11-03', 'time' => 1000], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('time');
    }

    public function test_aujourd_hui_une_prise_ou_une_observation_se_note_a_une_heure_passee(): void
    {
        $sejour = $this->sejour();
        $soin = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 600, 'acte' => 'Maropitant']);

        $this->postJson($this->url($sejour, "/soins/{$soin->id}/faire"), ['time' => 615], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->url($sejour, '/observations'), ['kind' => 'urine', 'value' => 'oui', 'sheet_day' => '2026-11-03', 'time' => 700], $this->enTetes($this->jeton))
            ->assertCreated()->assertJsonPath('data.time', '11:40')->assertJsonPath('data.is_late_note', false);

        $care = collect($this->ronde('2026-11-03', '2026-11-03')['stays'][0]['cares'])->firstWhere('id', $soin->id);
        $this->assertSame('10:15', $care['done_time']);
        $this->assertFalse($care['is_late_note']);
    }

    public function test_le_rapport_signe_ne_change_pas_et_la_note_tardive_s_ajoute_a_cote(): void
    {
        $sejour = $this->sejour();
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'times' => [1320], 'starts_at' => $sejour->admis_le]);
        $soin = SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-02', 'prevu_a' => 1320, 'acte' => 'Maropitant']);
        StaySheet::create(['sejour_id' => $sejour->id, 'day' => '2026-11-02', 'opened_at' => now(), 'report_status' => 'signed', 'report_text' => 'Rapport signé.', 'report_signed_at' => now()->subHours(10), 'report_signed_by_name' => 'Dr Anna Meyer']);

        $this->postJson($this->url($sejour, "/soins/{$soin->id}/faire"), ['time' => 1330], $this->enTetes($this->jetonAsv))->assertOk();

        $rapport = $this->getJson($this->url($sejour, '/feuilles/2026-11-02'), $this->enTetes($this->jeton))->assertOk()->json('data.report');
        $this->assertSame('Rapport signé.', $rapport['text']);
        $this->assertSame('signed', $rapport['status']);
        // Le libellé dit ce qui s'est passé (troisième vérification, 03.11.2026)
        $this->assertSame(['Maropitant donné à 22:10, noté après coup le 3.11 à 14:05 par Julie Muller'], array_column($rapport['late_additions'], 'label'));
        // Sans répétition : tout est dans le libellé (quatrième vérification)
        $this->assertNull($rapport['late_additions'][0]['late_label']);
    }

    // ————— Médicaments selon la dose : stock et facture —————

    public function test_chaque_prise_sort_du_stock_la_dose_donnee(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour();
        $this->postJson($this->url($sejour, '/traitements'), ['label' => 'Maropitant', 'product_id' => $produit->id, 'dose' => '1 mg/kg IV', 'dose_amount' => 2, 'times' => [1380]], $this->enTetes($this->jeton))->assertCreated();
        $prise = SejourSoin::query()->whereNotNull('treatment_id')->sole();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 23:05', 'Europe/Zurich'));
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('stock.dose', 2)->assertJsonPath('stock.dose_unit', 'ml');

        $stock = Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
        // Un flacon de 20 ml entamé, 18 ml restent dedans ; pas un flacon entier par prise
        $this->assertSame(2, $stock->quantite);
        $this->assertEquals(18.0, (float) $stock->opened_rest);
        $mouvement = MouvementStock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
        $this->assertSame(-1, $mouvement->quantite);
        $this->assertEquals(-2.0, (float) $mouvement->dose);
    }

    public function test_un_produit_sans_contenance_connue_se_complete_a_la_prescription(): void
    {
        $produit = $this->produit(['dose_unit' => null, 'content_per_unit' => null]);
        $sejour = $this->sejour();
        $donnees = ['label' => 'Maropitant', 'product_id' => $produit->id, 'dose_amount' => 2, 'times' => [1380]];

        $this->postJson($this->url($sejour, '/traitements'), $donnees, $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('product_content');
        $this->postJson($this->url($sejour, '/traitements'), [...$donnees, 'product_content' => 20, 'product_dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertCreated();
        $this->assertEquals(20.0, (float) $produit->refresh()->content_per_unit);
        $this->assertSame('ml', $produit->dose_unit);
    }

    public function test_le_medicament_est_facture_a_la_dose_et_les_soins_infirmiers_aux_seuls_soins(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour('2026-11-02 09:00', ['perfusion' => null]);
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'dose' => '1 mg/kg IV', 'dose_amount' => 2, 'product_id' => $produit->id, 'times' => [600], 'starts_at' => $sejour->admis_le]);
        foreach (['2026-11-02', '2026-11-03'] as $jour) {
            SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => $jour, 'prevu_a' => 600, 'acte' => 'Maropitant', 'dose' => '1 mg/kg IV', 'dose_amount' => 2, 'produit_id' => $produit->id, 'fait' => true, 'fait_le' => now()]);
        }
        SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-03', 'prevu_a' => 1320, 'acte' => 'Maropitant', 'dose_amount' => 2, 'produit_id' => $produit->id]);
        SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 480, 'acte' => 'Pesée', 'fait' => true, 'fait_le' => now()]);

        // Aperçu : forfait surveillance 95 × 2, un soin infirmier 15, Maropitant 4 ml × 60 / 20 = 12
        $apercu = $this->getJson($this->url($sejour, '/facture'), $this->enTetes($this->jeton))->assertOk()->json('data');
        $lignes = collect($apercu['lignes']);
        $this->assertSame(1, $lignes->firstWhere('libelle', 'Soins infirmiers réalisés durant le séjour')['quantite']);
        $med = $lignes->first(fn (array $l) => str_starts_with($l['libelle'], 'Maropitant'));
        $this->assertSame('Maropitant 1 mg/kg IV (Cerenia 10 mg/ml · 20 ml), 4 ml en 2 prises', $med['libelle']);
        $this->assertEquals(12.0, $med['total_chf']);
        $this->assertEquals(217.0, $apercu['total_chf']);

        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        $facture = Facture::withoutGlobalScopes()->sole();
        $this->assertSame(217.0, $facture->totalTtc());
        $ligne = $facture->lignes()->where('type', 'med')->sole();
        // Sans produit lié : l'encaissement ne ressort pas le stock déjà sorti à chaque prise
        $this->assertNull($ligne->produit_id);
        $this->assertEquals(12.0, (float) $ligne->prix_chf);
    }

    // ————— Rapport au propriétaire de l'admission —————

    public function test_le_rapport_part_au_proprietaire_qui_a_confie_l_animal(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/hospitalisation/sejours', ['patient_id' => $this->chien->id, 'box' => 3, 'motif' => 'Surveillance'], $this->enTetes($this->jeton))
            ->assertCreated()->assertJsonPath('data.client_id', $this->proprietaire->id);
        $sejour = Sejour::withoutGlobalScopes()->sole();
        $sejour->observations()->create(['day' => '2026-11-03', 'minute' => 700, 'kind' => 'eats', 'value' => 'tout', 'by_name' => 'Julie Muller']);

        // L'animal change de propriétaire pendant le séjour
        $nouveau = Client::factory()->create(['organisation_id' => $this->organisationId(), 'user_id' => User::factory()->create()->id]);
        $this->chien->forceFill(['client_id' => $nouveau->id])->save();

        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport'), [], $this->enTetes($this->jeton))->assertOk();
        $texte = StaySheet::query()->whereDate('day', '2026-11-03')->sole()->report_text;
        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport/signer'), ['text' => $texte], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport/envoyer'), [], $this->enTetes($this->jeton))->assertOk();

        $message = MessageClientele::query()->sole();
        $this->assertSame($this->proprietaire->user_id, $message->user_id);
        $this->assertSame(0, MessageClientele::query()->where('user_id', $nouveau->user_id)->count());

        // Une seule fois
        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport/envoyer'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertSame(1, MessageClientele::query()->count());
    }

    // ————— M2 : rapport corrigeable, écrit à la main, « Refaire » qui dit pourquoi —————

    public function test_un_rapport_a_relire_reste_corrigeable_et_signable_apres_un_refus(): void
    {
        $sejour = $this->sejour();
        StaySheet::create(['sejour_id' => $sejour->id, 'day' => '2026-11-03', 'opened_at' => now(), 'report_status' => 'to_review', 'report_text' => 'Texte rédigé.', 'report_drafts' => 5, 'report_source' => 'ai']);
        $sejour->observations()->create(['day' => '2026-11-03', 'minute' => 700, 'kind' => 'eats', 'value' => 'tout', 'by_name' => 'Julie Muller']);

        $rapport = $this->getJson($this->url($sejour, '/feuilles/2026-11-03'), $this->enTetes($this->jeton))->json('data.report');
        $this->assertFalse($rapport['can_draft']);
        $this->assertSame('Cinq rédactions déjà faites pour cette journée : corrigez le texte à la main.', $rapport['draft_blocked_reason']);

        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport'), [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.report.status', 'to_review')->assertJsonPath('data.report.text', 'Texte rédigé.')
            ->assertJsonPath('data.report.can_edit', true)->assertJsonPath('data.report.can_sign', true);
    }

    public function test_le_rapport_s_ecrit_a_la_main_sans_ia_puis_se_signe(): void
    {
        config(['services.anthropic.key' => null]);
        $sejour = $this->sejour();

        $rapport = $this->getJson($this->url($sejour, '/feuilles/2026-11-03'), $this->enTetes($this->jeton))->json('data.report');
        $this->assertFalse($rapport['can_draft']);
        $this->assertSame('L’IA n’est pas configurée sur ce serveur : écrivez le rapport à la main.', $rapport['draft_blocked_reason']);
        $this->assertTrue($rapport['can_edit']);

        $this->patchJson($this->url($sejour, '/feuilles/2026-11-03/rapport'), ['text' => 'Écrit à la main.'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.report.status', 'to_review')
            ->assertJsonPath('data.report.status_label', 'Écrit par l’équipe, à relire et signer');
        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport/signer'), ['text' => 'Écrit à la main.'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.report.status', 'signed');
    }

    // ————— M5 : on signe le texte enregistré, pas un autre —————

    public function test_la_signature_refuse_un_texte_different_du_dernier_enregistre(): void
    {
        $sejour = $this->sejour();
        StaySheet::create(['sejour_id' => $sejour->id, 'day' => '2026-11-03', 'opened_at' => now(), 'report_status' => 'to_review', 'report_text' => 'Version enregistrée.', 'report_source' => 'ai']);
        $url = $this->url($sejour, '/feuilles/2026-11-03/rapport/signer');

        $this->postJson($url, [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->postJson($url, ['text' => 'Version en cours de frappe.'], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->postJson($url, ['text' => 'Version enregistrée.'], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.report.status', 'signed');
    }

    // ————— M3 : changement d'heure —————

    public function test_l_heure_d_une_prise_est_l_heure_murale_aux_changements_d_heure(): void
    {
        $sejour = $this->sejour();
        $feuilles = app(StaySheets::class);

        // 25.10.2026 : on recule d'une heure ; 10:00 à Zurich = 09:00 UTC
        $this->assertSame('2026-10-25 09:00', $feuilles->instant($sejour, '2026-10-25', 600)->format('Y-m-d H:i'));
        // 28.03.2027 : on avance d'une heure ; 10:00 à Zurich = 08:00 UTC
        $this->assertSame('2027-03-28 08:00', $feuilles->instant($sejour, '2027-03-28', 600)->format('Y-m-d H:i'));
    }

    public function test_arreter_un_traitement_le_jour_du_changement_d_heure_garde_les_prises_passees(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:30', 'Europe/Zurich'));
        $sejour = $this->sejour('2026-10-24 09:00');
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'times' => [600, 660], 'starts_at' => $sejour->admis_le]);
        $this->ronde('2026-10-25', '2026-10-25');

        $this->postJson($this->url($sejour, "/traitements/{$traitement->id}/arreter"), [], $this->enTetes($this->jeton))->assertOk();

        // 10:00 est passée (gardée), 11:00 est à venir (retirée)
        $this->assertSame([600], SejourSoin::query()->whereDate('jour', '2026-10-25')->pluck('prevu_a')->all());
    }

    // ————— M1 et F8 : la feuille en cours la nuit, les compteurs servis —————

    public function test_la_nuit_la_feuille_en_cours_est_celle_de_la_veille_et_les_compteurs_sont_servis(): void
    {
        $sejour = $this->sejour();
        SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-04', 'prevu_a' => 60, 'acte' => 'Contrôle perfusion']);
        SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-04', 'prevu_a' => 120, 'acte' => 'Prise de sang']);

        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:30', 'Europe/Zurich'));
        $ronde = $this->ronde('2026-11-03', '2026-11-04');

        $this->assertSame('2026-11-03', $ronde['today']);
        $this->assertSame(['late' => 1, 'soon' => 1], $ronde['counts']);
        $this->assertSame(CarbonImmutable::parse('2026-11-04 01:05', 'Europe/Zurich')->addHour()->utc()->toIso8601String(), $ronde['counts_until']);
    }

    // ————— F5 : une rédaction compte une fois au budget —————

    public function test_une_redaction_qui_reessaie_ne_compte_qu_une_fois(): void
    {
        $sejour = $this->sejour();
        $sejour->observations()->create(['day' => '2026-11-03', 'minute' => 700, 'kind' => 'eats', 'value' => 'tout', 'by_name' => 'Julie Muller']);
        $feuille = StaySheet::create(['sejour_id' => $sejour->id, 'day' => '2026-11-03', 'opened_at' => now(), 'report_status' => 'drafting', 'report_drafts' => 1]);

        $job = (new DraftDailyReport($feuille->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        app()->call([$job, 'handle']);

        $this->assertSame(0, CompteurMensuel::valeur('anthropic:essais:rapport'));
        $this->assertSame('to_review', $feuille->refresh()->report_status);
    }

    // ————— F6 : les textes libres envoyés à l'IA sont nettoyés —————

    public function test_ni_telephone_ni_e_mail_ne_partent_a_l_ia(): void
    {
        $sejour = $this->sejour(plus: ['note' => 'Rappeler Mme Leroy au 079 123 45 67 ou +41 21 555 66 77, leroy@example.ch']);
        $sejour->observations()->create(['day' => '2026-11-03', 'minute' => 700, 'kind' => 'eats', 'value' => 'tout', 'by_name' => 'Julie Muller']);

        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport'), [], $this->enTetes($this->jeton))->assertOk();

        $faits = $this->writer->calls[0];
        $this->assertStringNotContainsString('079 123 45 67', $faits);
        $this->assertStringNotContainsString('555 66 77', $faits);
        $this->assertStringNotContainsString('leroy@example.ch', $faits);
        $this->assertStringContainsString('[téléphone]', $faits);
        $this->assertStringContainsString('[e-mail]', $faits);
    }

    // ————— F9 : un séjour sorti se relit, le rapport du jour de sortie se signe —————

    public function test_un_sejour_sorti_se_relit_et_le_rapport_du_jour_de_sortie_se_signe(): void
    {
        $sejour = $this->sejour();
        $sejour->observations()->create(['day' => '2026-11-03', 'minute' => 700, 'kind' => 'eats', 'value' => 'tout', 'by_name' => 'Julie Muller']);
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();

        $ronde = $this->ronde('2026-11-01', '2026-11-03', ['sejour_id' => $sejour->id]);
        $this->assertSame([$sejour->id], array_column($ronde['stays'], 'id'));
        $this->assertSame('tout', $ronde['stays'][0]['observations'][0]['value']);

        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport'), [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.report.status', 'to_review');
        $texte = StaySheet::query()->whereDate('day', '2026-11-03')->sole()->report_text;
        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport/signer'), ['text' => $texte], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.report.status', 'signed');
    }

    // ————— F10 et F11 : qui a admis, le référent, l'auteur des constantes —————

    public function test_admis_par_la_personne_qui_admet_et_les_constantes_gardent_leur_auteur(): void
    {
        $this->postJson('/api/v1/hospitalisation/sejours', ['patient_id' => $this->chien->id, 'box' => 3, 'motif' => 'Surveillance', 'veterinaire_id' => $this->vet->id], $this->enTetes($this->jetonAsv))
            ->assertCreated()
            ->assertJsonPath('data.admitted_by', 'Julie Muller')
            ->assertJsonPath('data.veterinaire.nom', 'Dr Rachel Solal');
        $sejour = Sejour::withoutGlobalScopes()->sole();

        $this->postJson($this->url($sejour, '/constantes'), ['mesure_a' => 840, 'temperature' => 38.4, 'fc' => 90, 'fr' => 22], $this->enTetes($this->jetonAsv))
            ->assertCreated()->assertJsonPath('data.by', 'Julie Muller');

        $jours = $this->getJson($this->url($sejour, '/jours'), $this->enTetes($this->jeton))->json('data');
        $this->assertSame('Julie Muller', $jours['admission']['by']);
        $this->assertSame('Dr Rachel Solal', $jours['admission']['referent']);
        $this->assertContains('Julie Muller', collect($jours['days'])->firstWhere('day', '2026-11-03')['carers']);
    }

    // ————— F13 : un soin pour un jour à venir —————

    public function test_un_soin_se_planifie_pour_un_jour_a_venir(): void
    {
        $sejour = $this->sejour();

        $this->postJson($this->url($sejour, '/soins'), ['sheet_day' => '2026-11-05', 'prevu_a' => 540, 'acte' => 'Radiographie de contrôle'], $this->enTetes($this->jeton))->assertCreated();

        $cares = $this->ronde('2026-11-05', '2026-11-05')['stays'][0]['cares'];
        $this->assertSame(['Radiographie de contrôle'], array_column($cares, 'label'));
    }

    // ————— F14 : le produit se choisit dans le stock —————

    public function test_les_medicaments_du_stock_se_listent_avec_leur_contenance_et_le_droit_stocks(): void
    {
        $produit = $this->produit();

        $this->getJson('/api/v1/hospitalisation/produits', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.id', $produit->id)
            ->assertJsonPath('data.0.dose_unit', 'ml')
            ->assertJsonPath('data.0.content_per_unit', 20);

        DerogationPermission::create(['employe_id' => $this->vet->id, 'domaine' => DomainePermission::Stocks, 'est_accordee' => false]);
        $this->getJson('/api/v1/hospitalisation/produits', $this->enTetes($this->jeton))->assertForbidden();
    }

    // ————— F15 : le poste qui fait le geste n'est pas annoncé à lui-même —————

    public function test_l_annonce_part_aux_autres_postes_seulement(): void
    {
        Event::fake([StayUpdated::class]);
        $sejour = $this->sejour();

        $this->postJson($this->url($sejour, '/douleurs'), ['score' => 2], [...$this->enTetes($this->jeton), 'X-Socket-ID' => '1234.5678'])->assertCreated();

        Event::assertDispatched(StayUpdated::class, fn (StayUpdated $e): bool => $e->socket === '1234.5678');
    }
}
