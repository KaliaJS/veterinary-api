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
 * SI ABV, suites de la neuvième vérification (08.11.2026) : le séjour sorti
 * sans arrêt du traitement recompte aussi ses jours ; à la réponse de
 * l'OSAV, l'envoyée remplacée se désactive dans la même tâche ; une refusée
 * garde son refus quand ses jours changent.
 */
class NeuviemePasseTest extends TestCase
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

    public function test_une_prise_notee_apres_la_sortie_d_un_traitement_jamais_arrete_se_recompte(): void
    {
        $sent = $this->traitementArreteDeclare();
        $this->treatment->update(['ends_at' => null]);
        $this->sejour->update(['sorti_le' => now()]);
        // La déclaration de la sortie disait 2 jours, la prise du jour était oubliée
        $sent->lines()->update(['days_1' => 2, 'total_quantity' => 2, 'given_quantity' => 2]);
        $this->prises[2]->update(['fait' => false, 'fait_le' => null]);

        $this->postJson("/api/v1/hospitalisation/sejours/{$this->sejour->id}/soins/{$this->prises[2]->id}/faire", ['time' => 480], $this->enTetes($this->jeton))->assertOk();

        $correction = AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $sent->id)->with('lines')->sole();
        $this->assertSame(3, $correction->lines[0]->days_1);
    }

    public function test_a_la_reponse_l_envoyee_remplacee_par_le_recompte_se_desactive_dans_la_meme_tache(): void
    {
        $enRoute = $this->traitementArreteDeclare(['status' => DeclarationStatus::Ready, 'sent_at' => null, 'sent_appendix' => null, 'is_outcome_unknown' => true]);
        $this->annuler($this->prises[2]);
        Http::fake([
            self::URL.'/jwt/create' => Http::response('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJDSEUtMTIzLjQ1Ni43ODkifQ.signature'),
            self::URL.'/psw/protocol' => Http::response([['uuid' => 'prot-9', 'prescriptionId' => $enRoute->recipe_number, 'createdOn' => '2026-11-08T10:00:00', 'status' => 'OK']]),
            self::URL.'/psw/prescription/deactivate/*' => Http::response(['status' => 'OK']),
        ]);

        (new SendAntibioticDeclaration($enRoute->id))->handle();

        // Désactivée tout de suite, sa correction à 2 jours relancée (une autre tâche, sans verrou commun)
        $this->assertSame(DeclarationStatus::Deactivated, $enRoute->fresh()->status);
        $correction = AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $enRoute->id)->sole();
        Queue::assertPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $correction->id);
    }

    public function test_une_refusee_garde_son_refus_quand_ses_jours_changent(): void
    {
        $refusee = $this->traitementArreteDeclare(['status' => DeclarationStatus::Rejected, 'sent_at' => null, 'sent_appendix' => null, 'osav_message' => 'Clé de diagnostic : la valeur manque.']);
        $numero = $refusee->recipe_number;
        $this->annuler($this->prises[2]);

        $refusee = $refusee->fresh(['lines']);
        $this->assertSame(DeclarationStatus::Rejected, $refusee->status);
        $this->assertSame('Clé de diagnostic : la valeur manque.', $refusee->osav_message);
        $this->assertSame($numero, $refusee->recipe_number);
        $this->assertSame(2, $refusee->lines[0]->days_1);
        $this->assertSame(2.0, $refusee->lines[0]->total_quantity);
    }
}
