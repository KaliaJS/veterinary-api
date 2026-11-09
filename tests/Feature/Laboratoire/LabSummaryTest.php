<?php

namespace Tests\Feature\Laboratoire;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutAnalyse;
use App\Jobs\WriteLabSummary;
use App\Mail\ReponseClinique;
use App\Models\Analyse;
use App\Models\AnalysePanel;
use App\Models\AnalyseResultat;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\LabSummary;
use App\Models\MessageClientele;
use App\Models\PanelLabo;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Services\CompteurMensuel;
use App\Services\Labs\LabSummaries;
use App\Services\Labs\LabSummarySources;
use App\Services\Labs\LabSummaryWriter;
use App\Services\Labs\LabSummaryWriterClaude;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\LabSummaryWriterFake;
use Tests\TestCase;

/**
 * Le message au propriétaire sur une analyse validée, par l'IA
 * (09.11.2026) : chaque valeur expliquée en une phrase courte, l'évolution
 * seulement sur 3 mesures en 2 ans (sens calculé par le code) ; rien
 * d'inventé ; relu, signé (droit de signer), envoyé dans la messagerie du
 * propriétaire ; budget de l'IA.
 */
class LabSummaryTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    private LabSummaryWriterFake $writer;

    private Analyse $analyse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 14:00', 'Europe/Zurich'));
        $this->app->instance(LabSummaryWriter::class, $this->writer = new LabSummaryWriterFake);
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anna Meyer']);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'user_id' => User::factory()->create()->id]);
        $this->patient = Patient::factory()->for($client)->create(['nom' => 'Rocky']);
        // Trois créatinines en neuf mois, et une plus vieille que deux ans (ignorée)
        $this->lab(now()->subYears(3), 300);
        $this->lab(now()->subMonths(9), 98);
        $this->lab(now()->subMonths(3), 120);
        $this->analyse = $this->lab(now()->subDay(), 180, 'cours');
        app(ContexteClinique::class)->oublier();
    }

    private function lab(\DateTimeInterface $at, float $creatinine, string $statut = 'ok'): Analyse
    {
        $analyse = Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => $statut, 'preleve_le' => $at]);
        $labo = PanelLabo::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'code' => 'bio'.$analyse->id, 'nom' => 'Biochimie']);
        $panel = AnalysePanel::query()->create(['analyse_id' => $analyse->id, 'panel_labo_id' => $labo->id, 'code' => 'bio', 'nom' => 'Biochimie', 'tube' => 'SEC']);
        AnalyseResultat::query()->create(['analyse_id' => $analyse->id, 'analyse_panel_id' => $panel->id, 'position' => 1, 'parametre' => 'Créatinine', 'unite' => 'µmol/l', 'borne_basse' => 44, 'borne_haute' => 133, 'valeur' => $creatinine, 'drapeau' => $creatinine > 133 ? 'H' : null]);
        AnalyseResultat::query()->create(['analyse_id' => $analyse->id, 'analyse_panel_id' => $panel->id, 'position' => 2, 'parametre' => 'Urée', 'unite' => 'mmol/l', 'borne_basse' => 2.5, 'borne_haute' => 9.6, 'valeur' => 6.1, 'drapeau' => null]);

        return $analyse;
    }

    private function uri(string $suffix = ''): string
    {
        return "/api/v1/laboratoire/analyses/{$this->analyse->id}/summary".$suffix;
    }

    private function validate(): void
    {
        $this->analyse->forceFill(['statut' => StatutAnalyse::Valide, 'valide_le' => now()])->save();
    }

    public function test_une_analyse_validee_a_son_resume_seulement_une_fois_validee(): void
    {
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->validate();
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.status', 'empty')
            ->assertJsonPath('data.section_labels', ['owner' => 'Pour le propriétaire']);
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.sections.owner', "La créatinine, qui reflète le travail des reins, est plus haute que la normale.\nElle est en hausse sur les trois dernières prises de sang.");
    }

    public function test_les_sources_cette_analyse_et_l_evolution_calculee_sur_trois_mesures_en_deux_ans(): void
    {
        $this->validate();
        $sources = app(LabSummarySources::class)->for($this->analyse->refresh());
        $this->assertSame('Créatinine 180 µmol/l (normes 44 à 133) : hors normes, au-dessus', $sources[0]['text']);
        $this->assertSame('Urée 6,1 mmol/l (normes 2,5 à 9,6) : dans les normes', $sources[1]['text']);
        // La créatinine de 2023 est ignorée ; 98, 120, 180 : en hausse, calculé par le code
        $this->assertSame('trend', $sources[2]['kind']);
        $this->assertSame('Créatinine en hausse sur 3 mesures en 9 mois (µmol/l) : 98 le 09.02.2026, 120 le 09.08.2026, 180 le 08.11.2026', $sources[2]['text']);
        // L'urée est stable : 6,1 trois fois
        $this->assertStringStartsWith('Urée stable sur 3 mesures', $sources[3]['text']);
        // Deux mesures seulement : pas d'évolution
        $other = Patient::factory()->for(Client::factory()->create(['organisation_id' => $this->patient->organisation_id]))->create();
        $this->patient = $other;
        $this->lab(now()->subMonths(6), 98);
        $two = $this->lab(now()->subDay(), 180);
        $this->assertSame(['value', 'value'], array_column(app(LabSummarySources::class)->for($two), 'kind'));
    }

    public function test_l_ia_n_invente_rien_chaque_phrase_porte_sa_source_et_aucun_diagnostic(): void
    {
        $this->validate();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $draft = $this->getJson($this->uri(), $this->enTetes($this->jeton))->json('data.draft');
        // La phrase dont la source n'existe pas est retirée
        $this->assertCount(2, $draft['sections']['owner']);
        $this->assertSame(['T1'], $draft['sections']['owner'][1]['sources']);
        config(['services.anthropic.key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['sections' => ['owner' => []]])]]])]);
        (new LabSummaryWriterClaude)->write([], []);
        Http::assertSent(function ($request) {
            $system = $request->data()['system'];
            $this->assertStringContainsString('Aucun diagnostic, aucune cause, aucun pronostic, aucun conseil', $system);
            $this->assertStringContainsString('sans rien en conclure', $system);
            $this->assertStringContainsString('chaque valeur que tu nommes est expliquée en une phrase courte', $system);
            $this->assertStringContainsString('sans les nommer', $system);
            $this->assertStringContainsString('tu ne le déduis jamais toi-même', $system);

            return true;
        });
    }

    public function test_relu_corrige_signe_avec_le_droit_puis_envoye_au_proprietaire(): void
    {
        $this->validate();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->patchJson($this->uri(), ['sections' => ['owner' => 'La créatinine, une valeur des reins, est plus haute que la normale.']], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.edited_sections', ['owner']);
        // Une partie corrigée reste à un nouveau brouillon
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.sections.owner', 'La créatinine, une valeur des reins, est plus haute que la normale.');
        // Pas encore signé : rien ne part
        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        ['employe' => $aide, 'jeton' => $aideJeton] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScopes()->create(['organisation_id' => $aide->organisation_id, 'nom' => 'Aide', 'domaines' => array_values(array_filter(DomainePermission::cases(), fn ($d) => $d !== DomainePermission::SignerComptesRendus))]);
        $aide->forceFill(['role_id' => $role->id])->save();
        app(ContexteClinique::class)->oublier();
        $this->postJson($this->uri('/sign'), [], $this->enTetes($aideJeton))->assertForbidden();
        app(ContexteClinique::class)->oublier();
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.is_signed', true)->assertJsonPath('data.signed_by_name', 'Dre Anna Meyer');
        $this->patchJson($this->uri(), ['sections' => ['owner' => 'Plus tard.']], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.sent_at', now()->toIso8601String());
        $message = MessageClientele::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('La créatinine, une valeur des reins, est plus haute que la normale.', (string) $message->texte);
        $this->assertStringNotContainsString('Diagnostic', (string) $message->texte);
        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_les_redactions_restent_dans_le_budget_de_l_ia(): void
    {
        $this->validate();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        $this->assertSame(1, CompteurMensuel::valeur('anthropic:redactions'));
        config(['services.anthropic.budget_usd' => 0]);
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonPath('errors.summary.0', 'L’IA n’est plus disponible ce mois-ci : écrivez le message vous-même.');
        $this->assertCount(1, $this->writer->calls);
    }

    public function test_verification_l_evolution_ne_prend_que_ce_qu_il_faut(): void
    {
        // Une analyse non validée, une d'une autre organisation, une plus récente : ignorées
        $this->lab(now()->subMonths(2), 500, 'cours');
        $foreign = Clinique::factory()->create();
        Analyse::factory()->create(['clinique_id' => $foreign->id, 'patient_id' => $this->patient->id, 'statut' => 'ok', 'preleve_le' => now()->subMonths(4)]);
        $this->lab(now()->addDays(2), 900);
        // La même valeur dans une autre unité : une autre série
        $mg = Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => 'ok', 'preleve_le' => now()->subMonths(5)]);
        $panel = AnalysePanel::query()->create(['analyse_id' => $mg->id, 'panel_labo_id' => PanelLabo::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'code' => 'mg', 'nom' => 'Bio'])->id, 'code' => 'mg', 'nom' => 'Bio', 'tube' => 'SEC']);
        AnalyseResultat::query()->create(['analyse_id' => $mg->id, 'analyse_panel_id' => $panel->id, 'position' => 1, 'parametre' => 'Créatinine', 'unite' => 'mg/dl', 'borne_basse' => 0.5, 'borne_haute' => 1.5, 'valeur' => 1.2]);
        $this->validate();
        $trend = collect(app(LabSummarySources::class)->for($this->analyse->refresh()))->firstWhere('kind', 'trend');
        $this->assertSame('Créatinine en hausse sur 3 mesures en 9 mois (µmol/l) : 98 le 09.02.2026, 120 le 09.08.2026, 180 le 08.11.2026', $trend['text']);
    }

    public function test_verification_une_valeur_vide_monte_et_descend_ou_le_meme_jour(): void
    {
        $this->validate();
        // Une analyse sans créatinine : pas d'évolution de la créatinine
        $this->analyse->resultats()->where('parametre', 'Créatinine')->update(['valeur' => null]);
        $sources = app(LabSummarySources::class)->for($this->analyse->refresh());
        $this->assertNull(collect($sources)->first(fn ($s) => $s['kind'] === 'trend' && $s['label'] === 'Créatinine'));
        // 98, 300, 100 : monté puis redescendu, « variable », jamais « stable »
        $other = Patient::factory()->for(Client::factory()->create(['organisation_id' => $this->patient->organisation_id]))->create();
        $this->patient = $other;
        $this->lab(now()->subMonths(6), 98);
        $this->lab(now()->subMonths(3), 300);
        $last = $this->lab(now()->subDay(), 100);
        $this->assertStringStartsWith('Créatinine variable sur 3 mesures en 6 mois', collect(app(LabSummarySources::class)->for($last))->firstWhere('kind', 'trend')['text']);
        // Trois mesures le même mois
        $this->patient = Patient::factory()->for(Client::factory()->create(['organisation_id' => $this->patient->organisation_id]))->create();
        $this->lab(now()->subDays(6), 98);
        $this->lab(now()->subDays(3), 120);
        $same = $this->lab(now()->subDay(), 180);
        $this->assertStringContainsString('sur 3 mesures en moins d’un mois', collect(app(LabSummarySources::class)->for($same))->firstWhere('kind', 'trend')['text']);
    }

    public function test_verification_l_e_mail_part_et_un_resume_signe_n_est_jamais_ecrase(): void
    {
        Mail::fake();
        $this->validate();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertOk();
        Mail::assertSent(ReponseClinique::class);
        // Une rédaction en retard ne touche pas un résumé signé
        $summary = LabSummary::withoutGlobalScopes()->where('analyse_id', $this->analyse->id)->firstOrFail();
        $before = $summary->sections;
        $summary->forceFill(['status' => 'writing'])->save();
        $summary->forceFill(['status' => 'signed'])->save();
        (new WriteLabSummary($summary->id))->handle($this->writer, app(LabSummarySources::class), app(LabSummaries::class));
        $this->assertSame($before, $summary->refresh()->sections);
    }

    public function test_verification_la_date_du_message_est_celle_de_la_clinique(): void
    {
        $this->clinique->forceFill(['fuseau_horaire' => 'Europe/Zurich'])->save();
        $this->analyse->forceFill(['preleve_le' => CarbonImmutable::parse('2026-11-08 23:30', 'UTC')])->save();
        $this->validate();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/send'), [], $this->enTetes($this->jeton))->assertOk();
        $this->assertStringContainsString('du 09.11.2026', (string) MessageClientele::query()->latest('id')->firstOrFail()->texte);
    }

    public function test_troisieme_verification_les_gardes_de_l_evolution(): void
    {
        $this->validate();
        // Une prise de sang plus tard le même jour n'entre pas dans le résumé de celle-ci
        $this->lab(CarbonImmutable::parse($this->analyse->preleve_le)->addHours(5), 250);
        // Une autre organisation, avec des valeurs : ignorée
        $foreign = Clinique::factory()->create();
        $fa = Analyse::factory()->create(['clinique_id' => $foreign->id, 'patient_id' => $this->patient->id, 'statut' => 'ok', 'preleve_le' => now()->subMonths(1)]);
        $fp = AnalysePanel::query()->create(['analyse_id' => $fa->id, 'panel_labo_id' => PanelLabo::factory()->create(['organisation_id' => $foreign->societe->organisation_id, 'code' => 'x', 'nom' => 'Bio'])->id, 'code' => 'x', 'nom' => 'Bio', 'tube' => 'SEC']);
        AnalyseResultat::query()->create(['analyse_id' => $fa->id, 'analyse_panel_id' => $fp->id, 'position' => 1, 'parametre' => 'Créatinine', 'unite' => 'µmol/l', 'borne_basse' => 44, 'borne_haute' => 133, 'valeur' => 999]);
        $trend = collect(app(LabSummarySources::class)->for($this->analyse->refresh()))->firstWhere('kind', 'trend');
        $this->assertSame('Créatinine en hausse sur 3 mesures en 9 mois (µmol/l) : 98 le 09.02.2026, 120 le 09.08.2026, 180 le 08.11.2026', $trend['text']);
    }

    public function test_troisieme_verification_en_baisse_au_dessous_et_valeur_absente(): void
    {
        $this->patient = Patient::factory()->for(Client::factory()->create(['organisation_id' => $this->patient->organisation_id]))->create();
        $this->lab(now()->subMonths(8), 130);
        $this->lab(now()->subMonths(5), 90);
        $this->lab(now()->subMonths(2), 60);
        $low = $this->lab(now()->subDay(), 30);
        $sources = collect(app(LabSummarySources::class)->for($low));
        $this->assertStringStartsWith('Créatinine en baisse sur 4 mesures', $sources->firstWhere('kind', 'trend')['text']);
        $this->assertSame('Créatinine 30 µmol/l (normes 44 à 133) : hors normes, au-dessous', $sources->first()['text']);
        // Quatre mesures avant, mais pas dans cette analyse : pas d'évolution
        $low->resultats()->where('parametre', 'Créatinine')->update(['valeur' => null]);
        $this->assertNull(collect(app(LabSummarySources::class)->for($low->refresh()))->first(fn ($s) => $s['kind'] === 'trend' && $s['label'] === 'Créatinine'));
    }

    public function test_troisieme_verification_signe_jamais_ecrase_et_plafond_de_redactions(): void
    {
        $this->validate();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton));
        $this->postJson($this->uri('/sign'), [], $this->enTetes($this->jeton))->assertOk();
        $summary = LabSummary::withoutGlobalScopes()->where('analyse_id', $this->analyse->id)->firstOrFail();
        $before = $summary->sections;
        $this->writer->result = ['sections' => ['owner' => [['text' => 'Autre texte.', 'sources' => ['V1']]]]];
        (new WriteLabSummary($summary->id))->handle($this->writer, app(LabSummarySources::class), app(LabSummaries::class));
        $this->assertSame(['signed', $before], [$summary->refresh()->status, $summary->sections]);
        // Le plafond de rédactions
        $other = $this->lab(now()->subHours(2), 150);
        $this->analyse = $other;
        $this->validate();
        foreach (range(1, (int) config('transcription.redactions_max')) as $i) {
            $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertAccepted();
        }
        $this->postJson($this->uri('/draft'), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonPath('errors.summary.0', 'Le brouillon a déjà été rédigé plusieurs fois : écrivez le message vous-même.');
    }

    public function test_une_autre_clinique_ne_voit_rien(): void
    {
        $this->validate();
        $other = Clinique::factory()->create();
        ['jeton' => $otherJeton] = $this->employeAvecJeton($other);
        app(ContexteClinique::class)->oublier();
        $this->getJson($this->uri(), $this->enTetes($otherJeton))->assertNotFound();
        $this->postJson($this->uri('/draft'), [], $this->enTetes($otherJeton))->assertNotFound();
    }
}
