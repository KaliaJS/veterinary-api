<?php

namespace Tests\Feature\Imports;

use App\Auth\ContexteClinique;
use App\Jobs\SplitRecordImport;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\RecordImport;
use App\Services\BudgetAnthropic;
use App\Services\CompteurMensuel;
use App\Services\Imports\RecordImports;
use App\Services\Imports\RecordSplitImpossible;
use App\Services\Imports\RecordSplitter;
use App\Services\Imports\RecordSplitterClaude;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\RecordSplitterFake;
use Tests\TestCase;

/**
 * Importer un gros PDF reçu et le découper (étape 3, 09.11.2026) : le texte
 * des pages et l'image des seules pages scannées vont à Claude Haiku 4.5,
 * qui propose les pièces (nom, sorte, date lus dans le PDF, rien
 * d'inventé) ; la proposition est remise en ordre ; le budget de l'IA est
 * tenu ; sans IA, la découpe se fait à la main.
 */
class RecordImportTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    private RecordSplitterFake $splitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 14:00', 'Europe/Zurich'));
        Storage::fake('prive');
        $this->app->instance(RecordSplitter::class, $this->splitter = new RecordSplitterFake);
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->patient = Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        app(ContexteClinique::class)->oublier();
    }

    /** @return array<string, mixed> */
    private function pages(): array
    {
        return ['file_name' => 'dossier-rocky.pdf', 'pages' => [
            ['text' => 'VETLAB Hématologie 02.10.2026 Rocky', 'image' => null],
            ['text' => 'Biochimie suite page 2/2', 'image' => null],
            ['text' => '', 'image' => base64_encode('JPEG-page-scannee')],
        ]];
    }

    public function test_le_texte_des_pages_et_l_image_des_seules_pages_scannees_vont_a_haiku_qui_propose_la_decoupe(): void
    {
        $id = $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", $this->pages(), $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->assertCount(1, $this->splitter->calls);
        $sent = $this->splitter->calls[0];
        $this->assertSame([1, 2, 3], array_column($sent, 'n'));
        $this->assertNull($sent[0]['image']);
        $this->assertSame(base64_encode('JPEG-page-scannee'), $sent[2]['image']);
        $this->getJson("/api/v1/record-imports/{$id}", $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.proposal.0.title', 'Hématologie et biochimie')
            ->assertJsonPath('data.proposal.0.kind', 'laboratoire')
            ->assertJsonPath('data.proposal.0.date', '2026-10-02')
            ->assertJsonPath('data.proposal.1.from', 3);
        // Le fichier provisoire des pages est effacé
        $this->assertSame([], Storage::disk('prive')->allFiles('imports'));
    }

    public function test_haiku_et_ses_consignes_interdisent_d_inventer(): void
    {
        config(['services.anthropic.key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['documents' => [['from' => 1, 'to' => 3, 'title' => 'Analyses', 'kind' => 'laboratoire', 'date' => null, 'source' => null]]])]]])]);
        $result = (new RecordSplitterClaude)->split([['n' => 1, 'text' => 'Hémato', 'image' => null], ['n' => 2, 'text' => '', 'image' => 'QUJD']]);
        $this->assertSame('Analyses', $result[0]['title']);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $this->assertSame('claude-haiku-4-5-20251001', $body['model']);
            $this->assertStringContainsString('N\'invente rien', $body['system']);
            $this->assertSame('image', $body['messages'][0]['content'][3]['type']);

            return true;
        });
    }

    public function test_la_proposition_est_remise_en_ordre_sans_trou_ni_chevauchement(): void
    {
        $out = RecordImports::normalize([
            ['from' => 4, 'to' => 9, 'title' => 'Rapport', 'kind' => 'rapport', 'date' => '2030-01-01', 'source' => ''],
            ['from' => 1, 'to' => 5, 'title' => '', 'kind' => 'inconnu', 'date' => '2026-02-30', 'source' => null],
        ], 6);
        $this->assertSame([[1, 3], [4, 6]], array_map(fn ($d) => [$d['from'], $d['to']], $out));
        $this->assertSame('Pages 1 à 3', $out[0]['title']);
        $this->assertSame('autre', $out[0]['kind']);
        // Une date impossible ou dans le futur n'est pas gardée
        $this->assertNull($out[0]['date']);
        $this->assertNull($out[1]['date']);
        $this->assertSame([['from' => 1, 'to' => 2, 'title' => 'Pages 1 à 2', 'kind' => 'autre', 'date' => null, 'source' => null]], RecordImports::normalize([], 2));
    }

    public function test_les_decoupes_restent_dans_le_budget_et_sans_ia_la_decoupe_se_fait_a_la_main(): void
    {
        $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", $this->pages(), $this->enTetes($this->jeton))->assertCreated();
        $this->assertSame(1, CompteurMensuel::valeur('anthropic:decoupes'));
        config(['services.anthropic.budget_usd' => 0]);
        $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", $this->pages(), $this->enTetes($this->jeton))->assertCreated()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'budget');
        $this->assertCount(1, $this->splitter->calls);
        // Une panne : la découpe se fait à la main
        config(['services.anthropic.budget_usd' => null]);
        $this->splitter->error = new RecordSplitImpossible('refus');
        $id = $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", $this->pages(), $this->enTetes($this->jeton))->json('data.id');
        $this->assertSame(['failed', 'splitter'], [RecordImport::withoutGlobalScopes()->find($id)->status, RecordImport::withoutGlobalScopes()->find($id)->error]);
    }

    public function test_verification_un_gros_pdf_compte_selon_sa_longueur_et_tient_dans_ce_que_haiku_lit(): void
    {
        $big = ['pages' => array_fill(0, 120, ['text' => str_repeat('a', 2000), 'image' => null])];
        $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", $big, $this->enTetes($this->jeton))->assertCreated();
        // 120 pages : trois tranches de 50
        $this->assertSame(3, CompteurMensuel::valeur('anthropic:decoupes'));
        $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", ['pages' => array_fill(0, 301, ['text' => 'x', 'image' => null])], $this->enTetes($this->jeton))->assertUnprocessable();
        // Le texte de 300 pages tient dans ce que Haiku lit
        config(['services.anthropic.key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['documents' => []])]]])]);
        (new RecordSplitterClaude)->split(array_map(fn ($n) => ['n' => $n, 'text' => str_repeat('é', 5000), 'image' => null], range(1, 300)));
        Http::assertSent(function ($request) {
            $chars = array_sum(array_map(fn ($b) => mb_strlen($b['text'] ?? ''), $request->data()['messages'][0]['content']));
            $this->assertLessThan(380000, $chars);
            $this->assertSame(16000, $request->data()['max_tokens']);

            return true;
        });
    }

    public function test_verification_les_pages_provisoires_restees_en_route_sont_effacees_et_une_decoupe_ne_se_clot_qu_une_fois(): void
    {
        $this->splitter->error = new \RuntimeException('Claude indisponible');
        $import = RecordImport::withoutGlobalScopes()->create(['organisation_id' => $this->patient->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'pages_count' => 2]);
        Storage::disk('prive')->put($import->pagesPath(), '[]');
        $this->travel(2)->days();
        $this->artisan('imports:purger')->assertSuccessful();
        $this->assertFalse(Storage::disk('prive')->exists($import->pagesPath()));
        $this->assertSame(['failed', 'splitter'], [$import->refresh()->status, $import->error]);
        $this->postJson("/api/v1/record-imports/{$import->id}/done", ['documents_count' => 2], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/record-imports/{$import->id}/done", ['documents_count' => 2], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_verification_une_decoupe_close_a_la_main_n_est_jamais_rouverte_par_l_ia(): void
    {
        $import = RecordImport::withoutGlobalScopes()->create(['organisation_id' => $this->patient->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'pages_count' => 3]);
        Storage::disk('prive')->put($import->pagesPath(), json_encode([['n' => 1, 'text' => 'a', 'image' => null]]));
        // Close à la main pendant que l'IA travaille
        $splitter = new class($import) implements RecordSplitter
        {
            public function __construct(private RecordImport $import) {}

            public function split(array $pages): array
            {
                $this->import->forceFill(['status' => 'done', 'documents_count' => 2])->save();

                return [['from' => 1, 'to' => 3, 'title' => 'Tout', 'kind' => 'autre', 'date' => null, 'source' => null]];
            }
        };
        (new SplitRecordImport($import->id))->handle($splitter);
        $this->assertSame('done', $import->refresh()->status);
        $this->assertNull($import->proposal);
        $this->assertFalse(Storage::disk('prive')->exists($import->pagesPath()));
    }

    public function test_verification_les_gardes_du_budget_de_la_taille_et_de_la_purge(): void
    {
        // Les découpes comptent dans la dépense, essais compris, par tranche de 50 pages
        config(['services.anthropic.cout_decoupe_usd' => 1.0, 'services.anthropic.cout_redaction_usd' => 0, 'services.anthropic.cout_suggestion_usd' => 0]);
        CompteurMensuel::ajouter('anthropic:decoupes', 2);
        $import = RecordImport::withoutGlobalScopes()->create(['organisation_id' => $this->patient->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'pages_count' => 120]);
        Storage::disk('prive')->put($import->pagesPath(), json_encode([['n' => 1, 'text' => 'a', 'image' => null]]));
        $job = new SplitRecordImport($import->id);
        $job->job = new class
        {
            public function attempts(): int
            {
                return 2;
            }
        };
        $job->handle($this->splitter);
        $this->assertSame(3, CompteurMensuel::valeur('anthropic:essais:decoupe'));
        $this->assertEqualsWithDelta(5.0, app(BudgetAnthropic::class)->depense(), 0.001);
        // Les images comptent dans la taille du texte envoyé à Haiku
        config(['services.anthropic.key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['documents' => []])]]])]);
        $pages = array_map(fn ($n) => ['n' => $n, 'text' => str_repeat('é', 5000), 'image' => $n <= 40 ? 'QUJD' : null], range(1, 300));
        (new RecordSplitterClaude)->split($pages);
        Http::assertSent(function ($request) {
            $chars = array_sum(array_map(fn ($b) => mb_strlen($b['text'] ?? ''), $request->data()['messages'][0]['content']));
            $this->assertLessThan(380000 - 4000 * 40 + 20000, $chars);

            return true;
        });
        // La purge ne relit que les 30 derniers jours
        $old = RecordImport::withoutGlobalScopes()->create(['organisation_id' => $this->patient->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'pages_count' => 1]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->artisan('imports:purger')->assertSuccessful();
        $this->assertSame('splitting', $old->refresh()->status);
    }

    public function test_seulement_l_animal_et_la_clinique_et_les_limites(): void
    {
        $other = Clinique::factory()->create();
        ['jeton' => $otherJeton] = $this->employeAvecJeton($other);
        app(ContexteClinique::class)->oublier();
        $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", $this->pages(), $this->enTetes($otherJeton))->assertNotFound();
        $id = $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", $this->pages(), $this->enTetes($this->jeton))->json('data.id');
        app(ContexteClinique::class)->oublier();
        $this->getJson("/api/v1/record-imports/{$id}", $this->enTetes($otherJeton))->assertNotFound();
        app(ContexteClinique::class)->oublier();
        $many = ['pages' => array_fill(0, 41, ['text' => '', 'image' => base64_encode('x')])];
        $this->postJson("/api/v1/patients/{$this->patient->id}/record-imports", $many, $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('pages');
        $this->postJson("/api/v1/record-imports/{$id}/done", ['documents_count' => 2], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.status', 'done');
    }
}
