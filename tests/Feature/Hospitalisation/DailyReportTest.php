<?php

namespace Tests\Feature\Hospitalisation;

use App\Enums\Espece;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\MessageClientele;
use App\Models\Patient;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StaySheet;
use App\Models\User;
use App\Services\BudgetAnthropic;
use App\Services\CompteurMensuel;
use App\Services\Hospitalisation\DailyReportImpossible;
use App\Services\Hospitalisation\DailyReportWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\DailyReportWriterFake;
use Tests\TestCase;

/**
 * « Un rapport de la journée, rédigé par l'IA depuis la feuille, à relire
 * et signer » (case de Jim, 03.11.2026). Même infrastructure que les
 * comptes rendus : file d'attente, budget mensuel d'Anthropic, compteur
 * du mois. Sans clé ni budget, le reste marche et le rapport le dit.
 */
class DailyReportTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Sejour $sejour;

    private DailyReportWriterFake $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:05', 'Europe/Zurich'));
        config(['services.anthropic.key' => 'cle-de-test', 'services.anthropic.budget_usd' => null]);
        $this->app->instance(DailyReportWriter::class, $this->writer = new DailyReportWriterFake);

        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Rachel Solal']);

        $patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'user_id' => User::factory()->create()->id]))
            ->create(['espece' => Espece::Chien, 'nom' => 'Tao']);

        $this->sejour = Sejour::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $patient->id,
            'box' => 2,
            'motif' => 'Insuffisance rénale décompensée',
            'admis_le' => CarbonImmutable::parse('2026-11-01 09:15', 'Europe/Zurich')->utc(),
        ]);

        SejourSoin::create(['sejour_id' => $this->sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 600, 'acte' => 'Maropitant', 'dose' => '1 mg/kg IV', 'fait' => true, 'fait_le' => now(), 'done_by_name' => 'Julie Muller']);
        $this->sejour->painScores()->create(['day' => '2026-11-03', 'minute' => 480, 'scale' => 'cmps_sf', 'score' => 8, 'max' => 24, 'threshold' => 6, 'by_name' => 'Dr Rachel Solal']);
        $this->sejour->observations()->create(['day' => '2026-11-03', 'minute' => 720, 'kind' => 'eats', 'value' => 'moitié', 'by_name' => 'Julie Muller']);
    }

    private function url(string $jour = '2026-11-03', string $suite = ''): string
    {
        return "/api/v1/hospitalisation/sejours/{$this->sejour->id}/feuilles/{$jour}/rapport{$suite}";
    }

    public function test_le_rapport_est_redige_par_l_ia_depuis_la_feuille_et_attend_la_relecture(): void
    {
        $this->postJson($this->url(), [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.report.status', 'to_review');

        // Rédigé depuis la feuille servie : ce qui a été donné, par qui, la douleur, les repas
        $this->assertCount(1, $this->writer->calls);
        $faits = $this->writer->calls[0];
        $this->assertStringContainsString('Maropitant', $faits);
        $this->assertStringContainsString('Julie Muller', $faits);
        $this->assertStringContainsString('8 sur 24', $faits);
        $this->assertStringContainsString('moitié', $faits);

        $this->getJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/feuilles/2026-11-03", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.report.status', 'to_review')
            ->assertJsonPath('data.report.status_label', 'Rédigé par l’IA depuis la feuille, à relire')
            ->assertJsonPath('data.report.text', "Tao a reçu son maropitant à 10:00.\nDouleur sous le seuil en fin de journée.");

        // Compté au budget du mois, comme les comptes rendus
        $this->assertSame(1, CompteurMensuel::valeur('anthropic:rapports'));
        $this->assertGreaterThan(0, app(BudgetAnthropic::class)->depense());
    }

    public function test_le_rapport_se_relit_puis_se_signe(): void
    {
        $this->postJson($this->url(), [], $this->enTetes($this->jeton))->assertOk();

        $this->patchJson($this->url(), ['text' => 'Texte relu et corrigé.'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.report.text', 'Texte relu et corrigé.');

        $this->postJson($this->url(suite: '/signer'), ['text' => 'Texte relu et corrigé.'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.report.status', 'signed')
            ->assertJsonPath('data.report.signed_by', 'Dr Rachel Solal')
            ->assertJsonPath('data.report.status_label', 'Relu et signé à 14:05 par Dr Rachel Solal');

        // Signé, il ne change plus
        $this->patchJson($this->url(), ['text' => 'Autre'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->url(), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertSame('Texte relu et corrigé.', StaySheet::query()->whereDate('day', '2026-11-03')->sole()->report_text);
    }

    public function test_sans_cle_le_rapport_dit_clairement_qu_il_n_a_pas_pu_etre_redige(): void
    {
        config(['services.anthropic.key' => null]);

        $this->postJson($this->url(), [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.report.status', 'failed')
            ->assertJsonPath('data.report.status_label', 'Le rapport n’a pas pu être rédigé : l’IA n’est pas configurée sur ce serveur.');

        $this->assertSame([], $this->writer->calls);
    }

    public function test_budget_atteint_ou_ia_en_panne_le_rapport_le_dit(): void
    {
        config(['services.anthropic.budget_usd' => 0]);
        $this->postJson($this->url(), [], $this->enTetes($this->jeton))
            ->assertJsonPath('data.report.status', 'failed')
            ->assertJsonPath('data.report.status_label', 'Le rapport n’a pas pu être rédigé : le budget de l’IA de ce mois est atteint.');

        config(['services.anthropic.budget_usd' => null]);
        $this->writer->error = new DailyReportImpossible('Crédit épuisé');
        $this->postJson($this->url(), [], $this->enTetes($this->jeton))
            ->assertJsonPath('data.report.status', 'failed')
            ->assertJsonPath('data.report.status_label', 'Le rapport n’a pas pu être rédigé : l’IA n’a pas répondu. Réessayez plus tard.');
    }

    public function test_un_jour_a_venir_n_a_pas_de_rapport(): void
    {
        $this->postJson($this->url('2026-11-05'), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->postJson($this->url('2026-10-30'), [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_le_rapport_signe_s_envoie_au_proprietaire_par_sa_messagerie(): void
    {
        Mail::fake();
        $this->postJson($this->url(), [], $this->enTetes($this->jeton))->assertOk();

        // Pas encore signé : il ne part pas
        $this->postJson($this->url(suite: '/envoyer'), [], $this->enTetes($this->jeton))->assertUnprocessable();

        $this->postJson($this->url(suite: '/signer'), ['text' => "Tao a reçu son maropitant à 10:00.\nDouleur sous le seuil en fin de journée."], $this->enTetes($this->jeton))->assertJsonPath('data.report.can_send', true);
        $this->postJson($this->url(suite: '/envoyer'), [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.report.is_sent', true);

        $message = MessageClientele::query()->sole();
        $this->assertSame('clinique', $message->auteur);
        $this->assertStringContainsString('Tao a reçu son maropitant à 10:00.', $message->texte);
    }
}
