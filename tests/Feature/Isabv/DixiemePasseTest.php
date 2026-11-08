<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV, suites de la dixième vérification (08.11.2026) : la tâche
 * d'envoi reprise après un échec du recompte le refait.
 */
class DixiemePasseTest extends TestCase
{
    use EmetDesJetonsApi;
    use PrepareLeSiAbv;
    use RefreshDatabase;

    private const URL = 'https://isabv.test/api/isabv';

    private Clinique $clinique;

    private Patient $patient;

    private string $jeton;

    private Sejour $sejour;

    private StayTreatment $treatment;

    /** @var list<SejourSoin> */
    private array $prises;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.isabv.url' => self::URL]);
        Queue::fake();
        $this->donneesDeBaseSiAbv();
        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $this->cliniqueQuiDeclare($this->clinique);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->patient = Patient::factory()->for($client)->create(['espece' => 'CH']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    /** Un traitement arrêté, trois jours de prises données, sa déclaration à 3 jours */
    private function traitementArreteDeclare(array $attributs = []): AntibioticDeclaration
    {
        $this->sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id, 'box' => 2, 'statut' => 'surv', 'perfusion' => null, 'admis_le' => now()->subDays(4)]);
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01']);
        $this->treatment = StayTreatment::create(['sejour_id' => $this->sejour->id, 'label' => 'Amoxicilline', 'product_id' => $produit->id, 'times' => [480], 'starts_at' => now()->subDays(3), 'ends_at' => now()]);
        $this->prises = array_map(fn (int $avant) => SejourSoin::create(['sejour_id' => $this->sejour->id, 'treatment_id' => $this->treatment->id, 'jour' => aujourdhui()->subDays($avant)->toDateString(),
            'prevu_a' => 480, 'acte' => 'Amoxicilline', 'fait' => true, 'fait_le' => now()->subHour(), 'produit_id' => $produit->id]), [2, 1, 0]);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment, 'status' => DeclarationStatus::Sent, 'sent_at' => now(), 'sent_appendix' => '',
            'source_type' => $this->treatment->getMorphClass(), 'source_id' => $this->treatment->id, 'patient_id' => $this->patient->id, 'visit_on' => aujourdhui(), 'species_code' => '1.5',
            'weight_kg' => 30, 'diagnosis_key' => '3', ...$attributs,
        ]);
        $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '11', 'days_1' => 3, 'total_quantity' => 3, 'given_quantity' => 3]);

        return $declaration;
    }

    private function annuler(SejourSoin $prise): void
    {
        $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/soins/{$prise->id}/annuler", [], $this->enTetes($this->jeton))->assertOk();
    }

    /** @return array<int, array{string, int|null, int}> statut, déclaration corrigée, jours, par numéro */
    private function chaine(): array
    {
        return AntibioticDeclaration::withoutGlobalScopes()->with('lines')->orderBy('id')->get()
            ->mapWithKeys(fn (AntibioticDeclaration $d) => [$d->id => [$d->status->value, $d->replaces_id, $d->lines[0]->days_1]])->all();
    }

    public function test_la_tache_reprise_apres_un_echec_du_recompte_le_refait(): void
    {
        // Partie à 3 jours ; le recompte de la réponse a échoué, une prise reste annulée
        $sent = $this->traitementArreteDeclare();
        $this->prises[2]->update(['cancelled_at' => now()]);
        Http::fake([
            self::URL.'/jwt/create' => Http::response('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJDSEUtMTIzLjQ1Ni43ODkifQ.signature'),
            self::URL.'/psw/prescription/deactivate/*' => Http::response(['status' => 'OK']),
        ]);

        (new SendAntibioticDeclaration($sent->id))->handle();

        $correction = AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $sent->id)->with('lines')->sole();
        $this->assertSame(2, $correction->lines[0]->days_1);
        // L'envoyée remplacée se désactive dans la même tâche
        $this->assertSame(DeclarationStatus::Deactivated, $sent->fresh()->status);
    }

    public function test_la_tache_reprise_ne_desactive_pas_sous_un_autre_complement_que_celui_de_l_envoi(): void
    {
        // Partie sous « Ancien site » ; la clinique a changé de complément avant la reprise
        $sent = $this->traitementArreteDeclare(['sent_appendix' => 'Ancien site']);
        $this->prises[2]->update(['cancelled_at' => now()]);
        Http::fake([
            self::URL.'/jwt/create' => Http::response('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJDSEUtMTIzLjQ1Ni43ODkifQ.signature'),
            self::URL.'/psw/prescription/deactivate/*' => Http::response(['status' => 'OK']),
        ]);

        (new SendAntibioticDeclaration($sent->id))->handle();

        $sent = $sent->fresh();
        $this->assertSame(DeclarationStatus::Replaced, $sent->status);
        $this->assertStringContainsString('ancien complément « Ancien site »', (string) $sent->osav_message);
        $this->assertTrue($sent->isDeactivatedOnlyOnWeb());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/psw/prescription/deactivate/'));
    }
}
