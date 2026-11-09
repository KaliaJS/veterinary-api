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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV, suites de la huitième vérification (08.11.2026) : les jours d'un
 * traitement arrêté se recomptent sur la dernière déclaration de la chaîne
 * (après une première correction, pendant qu'une correction attend, à la
 * réponse d'un envoi en route, quand une prise se note de nouveau) ; une
 * saisie sur le site n'est plus relancée et son annulation le dit ;
 * « désactivée sur le site » suit un indicateur de l'API.
 */
class HuitiemePasseTest extends TestCase
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
        // À midi : la nuit, la prise de 08:00 serait « prévue plus tard » (plus de 2 h en avance) et refusée
        $this->travelTo(now('Europe/Zurich')->setTime(12, 0));
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

    public function test_deux_annulations_de_suite_reprennent_la_correction_qui_attend(): void
    {
        $sent = $this->traitementArreteDeclare();
        $this->annuler($this->prises[2]);
        $this->annuler($this->prises[1]);

        $correction = AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $sent->id)->with('lines')->sole();
        $this->assertSame(1, $correction->lines[0]->days_1);
        $this->assertSame(DeclarationStatus::Ready, $correction->status);
        $this->assertCount(2, $this->chaine());
    }

    public function test_une_annulation_apres_une_premiere_correction_partie_corrige_la_correction(): void
    {
        $sent = $this->traitementArreteDeclare();
        $this->annuler($this->prises[2]);
        $correction = AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $sent->id)->sole();
        // La première correction est partie, l'ancienne désactivée
        $sent->forceFill(['status' => DeclarationStatus::Deactivated, 'deactivated_at' => now()])->save();
        $correction->forceFill(['status' => DeclarationStatus::Sent, 'sent_at' => now(), 'sent_appendix' => ''])->save();

        $this->annuler($this->prises[1]);

        $seconde = AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $correction->id)->with('lines')->sole();
        $this->assertSame(1, $seconde->lines[0]->days_1);
        $this->assertSame(DeclarationStatus::Replaced, $correction->fresh()->status);
    }

    public function test_une_prise_annulee_pendant_l_envoi_se_recompte_a_la_reponse(): void
    {
        $enRoute = $this->traitementArreteDeclare(['status' => DeclarationStatus::Ready, 'sent_at' => null, 'sent_appendix' => null, 'is_outcome_unknown' => true]);
        $this->annuler($this->prises[2]);
        $this->assertSame([$enRoute->id => ['ready', null, 3]], $this->chaine());

        // L'OSAV l'avait reçue (protocole) : elle est partie à 3 jours, une correction à 2 jours la remplace
        Http::fake([
            self::URL.'/jwt/create' => Http::response('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJDSEUtMTIzLjQ1Ni43ODkifQ.signature'),
            self::URL.'/psw/protocol' => Http::response([['uuid' => 'prot-9', 'prescriptionId' => $enRoute->recipe_number, 'createdOn' => '2026-11-08T10:00:00', 'status' => 'OK']]),
            self::URL.'/psw/prescription/deactivate/*' => Http::response(['status' => 'OK']),
        ]);
        (new SendAntibioticDeclaration($enRoute->id))->handle();

        $correction = AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $enRoute->id)->with('lines')->sole();
        $this->assertSame(2, $correction->lines[0]->days_1);
        $this->assertSame(DeclarationStatus::Ready, $correction->status);
        // Remplacée, et désactivée dans la même tâche (neuvième vérification)
        $this->assertSame(DeclarationStatus::Deactivated, $enRoute->fresh()->status);
    }

    public function test_une_prise_annulee_par_erreur_puis_notee_de_nouveau_se_recompte(): void
    {
        $this->traitementArreteDeclare();
        $this->annuler($this->prises[2]);
        $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/soins/{$this->prises[2]->id}/faire", [], $this->enTetes($this->jeton))->assertOk();

        // Ce qui partira (ou restera) à l'OSAV dit 3 jours, ce qui a été donné
        $active = AntibioticDeclaration::withoutGlobalScopes()->with('lines')->whereNotIn('status', [DeclarationStatus::Replaced, DeclarationStatus::Deactivated])->orderByDesc('id')->first();
        $this->assertSame(3, $active->lines[0]->days_1);
    }

    public function test_une_saisie_sur_le_site_n_est_plus_relancee_et_son_annulation_le_dit(): void
    {
        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_OSAV_WEB]);
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $prise = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment, 'status' => DeclarationStatus::Ready, 'is_outcome_unknown' => true,
            'source_type' => $ordonnance->getMorphClass(), 'source_id' => $ordonnance->id, 'patient_id' => $this->patient->id, 'visit_on' => aujourdhui(), 'species_code' => '1.5', 'weight_kg' => 30, 'diagnosis_key' => '3',
        ]);
        $prise->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '11', 'days_1' => 2, 'total_quantity' => 2, 'given_quantity' => 2]);
        $this->postJson('/api/v1/isabv/declarations/'.$prise->id.'/entered-on-web', [], $this->enTetes($this->jeton))->assertOk();
        $this->assertFalse($prise->fresh()->is_outcome_unknown);

        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_VETERINARY]);
        $prise->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $this->artisan('isabv:send')->assertSuccessful();
        Queue::assertNotPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $prise->id);

        // Même encore marquée en route, une saisie sur le site annulée le dit, sans passer en remplacée
        $prise->forceFill(['is_outcome_unknown' => true])->saveQuietly();
        app(Declarations::class)->cancelFor($ordonnance);
        $this->assertSame(DeclarationStatus::EnteredOnWeb, $prise->fresh()->status);
        $this->assertStringContainsString('désactivez aussi cette déclaration', (string) $prise->fresh()->osav_message);
    }

    public function test_les_jours_changes_d_une_saisie_sur_le_site_se_disent(): void
    {
        $web = $this->traitementArreteDeclare(['status' => DeclarationStatus::EnteredOnWeb, 'sent_at' => null, 'sent_appendix' => null]);
        $this->annuler($this->prises[2]);

        $this->assertSame(DeclarationStatus::EnteredOnWeb, $web->fresh()->status);
        $this->assertStringContainsString('compte maintenant 2 jours', (string) $web->fresh()->osav_message);
    }

    public function test_l_api_dit_quand_la_desactivation_se_fait_sur_le_site(): void
    {
        $sent = $this->traitementArreteDeclare(['status' => DeclarationStatus::Replaced, 'osav_message' => 'L’OSAV la tient pour un doublon.']);
        $this->getJson('/api/v1/isabv/declarations/'.$sent->id, $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.is_deactivated_only_on_web', false);

        $sent->forceFill(['sent_appendix' => 'Site A'])->save();
        $this->getJson('/api/v1/isabv/declarations/'.$sent->id, $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.is_deactivated_only_on_web', true);
    }
}
