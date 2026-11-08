<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\IsabvConnection;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\StayTreatment;
use App\Models\Stock;
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
 * SI ABV, suites de la cinquième vérification (08.11.2026) : un traitement
 * sans prise se désactive même parti ; le renouvellement déclare un article
 * relié depuis ; une désactivation part sous le complément de l'envoi ; on ne réactive que ce
 * que l'OSAV tient pour un doublon ; les déclarations d'un vétérinaire parti
 * comptent chez qui prescrit ; un équidé se déclare en « EH » ; le
 * complément refuse $ et %.
 */
class CinquiemePasseTest extends TestCase
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

    private function sent(array $attributs = []): AntibioticDeclaration
    {
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment, 'status' => DeclarationStatus::Sent, 'sent_at' => now(),
            'source_type' => $ordonnance->getMorphClass(), 'source_id' => $ordonnance->id, 'patient_id' => $this->patient->id, 'visit_on' => aujourdhui(), 'species_code' => '1.5', ...$attributs,
        ]);
        $declaration->lines()->create(['preparation_code' => '12345-01', 'dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7, 'total_quantity' => 14, 'given_quantity' => 14]);

        return $declaration;
    }

    public function test_un_traitement_arrete_sans_prise_se_desactive_meme_parti(): void
    {
        Queue::fake();
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id, 'box' => 2, 'statut' => 'surv', 'perfusion' => null, 'admis_le' => now()->subDay()]);
        $treatment = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Amoxicilline', 'times' => [480], 'starts_at' => now()]);
        $declaration = $this->sent();
        $declaration->update(['source_type' => $treatment->getMorphClass(), 'source_id' => $treatment->id]);

        app(Declarations::class)->treatmentStopped($treatment);

        $this->assertSame(DeclarationStatus::Replaced, $declaration->fresh()->status);
        Queue::assertPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $declaration->id);
    }

    public function test_le_renouvellement_declare_un_article_relie_depuis_la_signature(): void
    {
        Queue::fake();
        $a = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01']);
        $b = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        foreach ([$a, $b] as $p) {
            Stock::factory()->create(['produit_id' => $p->id, 'clinique_id' => $this->clinique->id, 'quantite' => 20]);
        }
        $ordonnance = $this->postJson('/api/v1/ordonnances', ['patient_id' => $this->patient->id, 'renouvellements' => 1,
            'lignes' => [['produit_id' => $a->id, 'posologie' => '1 cp'], ['produit_id' => $b->id, 'posologie' => '1 cp']]], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $b->update(['isabv_preparation_code' => '22222-01']);

        $renewal = $this->postJson('/api/v1/ordonnances/'.$ordonnance['id'].'/renouveler', [], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $codes = AntibioticDeclaration::withoutGlobalScopes()->where('source_id', $renewal['id'])->with('lines')->get()->flatMap->lines->pluck('preparation_code')->sort()->values()->all();
        $this->assertSame(['12345-01', '22222-01'], $codes);
    }

    public function test_une_desactivation_part_sous_le_complement_de_l_envoi(): void
    {
        $declaration = $this->sent(['status' => DeclarationStatus::Replaced, 'sent_appendix' => 'Site de Bulle']);
        IsabvConnection::withoutGlobalScopes()->update(['appendix' => 'Site de Bulle']);
        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/prescription/deactivate/*' => Http::response(['status' => 'OK'])]);

        (new SendAntibioticDeclaration($declaration->id))->handle();

        $this->assertSame(DeclarationStatus::Deactivated, $declaration->fresh()->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/jwt/create') && $request['appendix'] === 'Site de Bulle');
    }

    public function test_on_ne_reactive_que_ce_que_l_osav_tient_pour_un_doublon(): void
    {
        $declaration = $this->sent(['form' => IsabvForm::StockSupply]);
        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/prescription/getDuplicatePrescriptions' => Http::response(['prescriptionIDs' => ['autre']])]);

        $this->postJson('/api/v1/isabv/duplicates/reactivate', ['ids' => [$declaration->id]], $this->enTetes($this->jeton))->assertUnprocessable();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'reactivate'));
    }

    public function test_les_declarations_d_un_veterinaire_parti_comptent_chez_qui_prescrit(): void
    {
        ['employe' => $parti] = $this->employeAvecJeton($this->clinique);
        Employe::withoutGlobalScopes()->whereKey($parti->id)->update(['fin_contrat_le' => now()->subMonth()->toDateString()]);
        $this->sent(['status' => DeclarationStatus::ToComplete, 'sent_at' => null, 'employe_id' => $parti->id]);

        $group = collect($this->withToken($this->jeton)->getJson('/api/v1/unfinished-work')->assertOk()->json('data.groups'))->firstWhere('kind', 'antibiotic_declaration');
        $this->assertSame(1, $group['count']);
    }

    public function test_un_equide_se_declare_en_eh_et_le_complement_refuse_dollar_et_pourcent(): void
    {
        $cheval = Patient::factory()->for($this->patient->client)->create(['espece' => 'CV', 'race' => null]);
        $this->assertSame('1.1', Declarations::defaultSpecies($cheval));
        $this->assertSame(IsabvForm::CompanionTreatment, IsabvForm::forSpecies('1.1'));

        $admin = $this->enTetes($this->administrateurAvecJeton($this->clinique)['jeton']);
        foreach (['Site $1', 'Site 50%'] as $appendix) {
            $this->putJson('/api/v1/isabv/connection', ['variant' => 1, 'appendix' => $appendix], $admin)->assertUnprocessable()->assertJsonValidationErrors('appendix');
        }
    }
}
