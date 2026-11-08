<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\IsabvConnection;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use App\Services\Isabv\Declarations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV, suites de la sixième vérification (08.11.2026) : en variante 2,
 * Veterinary n'envoie rien (ni envoi, ni désactivation, ni correction) et
 * dit ce qui se fait sur le site de l'OSAV ; « envoyée sans complément » se
 * garde ; une désactivation sous un ancien complément se fait sur le site
 * de l'OSAV, puis sa correction part ; des prises annulées après l'arrêt
 * retirent la déclaration ; le réglage compte ce qui n'est pas parti.
 */
class SixiemePasseTest extends TestCase
{
    use EmetDesJetonsApi;
    use PrepareLeSiAbv;
    use RefreshDatabase;

    private const URL = 'https://isabv.test/api/isabv';

    private Clinique $clinique;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.isabv.url' => self::URL]);
        $this->donneesDeBaseSiAbv();
        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $this->cliniqueQuiDeclare($this->clinique);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->patient = Patient::factory()->for($client)->create(['espece' => 'CH']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function declaration(array $attributs = []): AntibioticDeclaration
    {
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment, 'status' => DeclarationStatus::Sent, 'sent_at' => now(),
            'source_type' => $ordonnance->getMorphClass(), 'source_id' => $ordonnance->id, 'patient_id' => $this->patient->id, 'visit_on' => aujourdhui(), 'species_code' => '1.5',
            'weight_kg' => 30, 'diagnosis_key' => '3', ...$attributs,
        ]);
        $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7, 'total_quantity' => 14, 'given_quantity' => 14]);

        return $declaration;
    }

    public function test_en_variante_2_rien_ne_part_et_ce_qui_se_fait_sur_le_site_de_l_osav_se_dit(): void
    {
        Queue::fake();
        Http::fake();
        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_OSAV_WEB]);
        $replaced = $this->declaration(['status' => DeclarationStatus::Replaced]);
        (new SendAntibioticDeclaration($replaced->id))->handle();
        Http::assertNothingSent();
        $this->assertSame(DeclarationStatus::Replaced, $replaced->fresh()->status);

        // Une envoyée ne se corrige plus ici ; annulée, elle le dit
        $sent = $this->declaration();
        $this->getJson('/api/v1/isabv/declarations/'.$sent->id, $this->enTetes($this->jeton))->assertJsonPath('data.is_editable', false);
        $this->putJson('/api/v1/isabv/declarations/'.$sent->id, ['isabv' => ['weight_kg' => 29]], $this->enTetes($this->jeton))->assertUnprocessable();
        app(Declarations::class)->cancelFor(Ordonnance::withoutGlobalScopes()->find($sent->source_id));
        $this->assertSame(DeclarationStatus::Sent, $sent->fresh()->status);
        $this->assertStringContainsString('désactivez aussi cette déclaration dans l’application web de l’OSAV', (string) $sent->fresh()->osav_message);
        Queue::assertNothingPushed();
    }

    public function test_envoyee_sans_complement_cela_se_garde(): void
    {
        $declaration = $this->declaration(['status' => DeclarationStatus::Ready, 'sent_at' => null]);
        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/prescription/EH' => Http::response(['protocol' => ['status' => 'OK']], 201)]);

        (new SendAntibioticDeclaration($declaration->id))->handle();

        $this->assertSame('', $declaration->fresh()->sent_appendix);
    }

    public function test_envoyee_sans_complement_elle_se_desactive_sans_complement(): void
    {
        // La clinique s'est réinscrite avec un complément : la désactivation part sans, et l'OSAV refuse ce token-là
        $declaration = $this->declaration(['status' => DeclarationStatus::Replaced, 'sent_appendix' => '']);
        IsabvConnection::withoutGlobalScopes()->update(['appendix' => 'Site B']);
        Http::fake([self::URL.'/jwt/create' => Http::response('', 401)]);

        (new SendAntibioticDeclaration($declaration->id))->handle();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/jwt/create') && ! isset($request['appendix']));
        $this->assertNotNull(IsabvConnection::withoutGlobalScopes()->sole()->verified_at);
        $this->assertStringContainsString('ancien complément', (string) $declaration->fresh()->osav_message);
    }

    public function test_une_desactivation_sous_un_ancien_complement_se_fait_sur_le_site_puis_la_correction_part(): void
    {
        Queue::fake();
        $old = $this->declaration(['status' => DeclarationStatus::Replaced, 'sent_appendix' => 'Site A', 'osav_message' => 'Envoyée sous l’ancien complément « Site A » : désactivez-la dans l’application web de l’OSAV.']);
        $old->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $correction = $this->declaration(['status' => DeclarationStatus::Ready, 'sent_at' => null, 'replaces_id' => $old->id]);
        IsabvConnection::withoutGlobalScopes()->update(['appendix' => 'Site B']);

        $this->artisan('isabv:send')->assertSuccessful();
        Queue::assertNotPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $old->id);

        $this->postJson('/api/v1/isabv/declarations/'.$old->id.'/deactivated-on-web', [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.status', 'deactivated');
        Queue::assertPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $correction->id);
        $this->postJson('/api/v1/isabv/declarations/'.$correction->id.'/deactivated-on-web', [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_des_prises_annulees_apres_l_arret_retirent_la_declaration(): void
    {
        Queue::fake();
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id, 'box' => 2, 'statut' => 'surv', 'perfusion' => null, 'admis_le' => now()->subDay()]);
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01']);
        $treatment = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Amoxicilline', 'product_id' => $produit->id, 'times' => [480], 'starts_at' => now()->subHours(3), 'ends_at' => now()]);
        $declaration = $this->declaration(['source_type' => $treatment->getMorphClass(), 'source_id' => $treatment->id]);
        $prise = SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $treatment->id, 'jour' => aujourdhui()->toDateString(), 'prevu_a' => 480, 'acte' => 'Amoxicilline', 'fait' => true, 'fait_le' => now()->subHours(2), 'produit_id' => $produit->id]);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$prise->id}/annuler", [], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(DeclarationStatus::Replaced, $declaration->fresh()->status);
        Queue::assertPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $declaration->id);
    }

    public function test_le_reglage_compte_ce_qui_n_est_pas_parti(): void
    {
        $this->declaration(['status' => DeclarationStatus::ToComplete, 'sent_at' => null]);
        $this->declaration(['status' => DeclarationStatus::Ready, 'sent_at' => null]);
        $this->declaration();

        $this->getJson('/api/v1/isabv/connection', $this->enTetes($this->administrateurAvecJeton($this->clinique)['jeton']))->assertJsonPath('data.pending_count', 2);
    }
}
