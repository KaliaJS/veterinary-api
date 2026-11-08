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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV, suites de la septième vérification (08.11.2026) : une prise
 * annulée après l'arrêt recompte les jours, même d'une déclaration partie ;
 * en variante 2, ce qui n'est pas parti se dit saisi sur le site de l'OSAV
 * et ne repart pas au retour en variante 1 ; « désactivée sur le site »
 * seulement pour un ancien complément ; le complément de l'envoi ne se
 * recopie pas.
 */
class SeptiemePasseTest extends TestCase
{
    use EmetDesJetonsApi;
    use PrepareLeSiAbv;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.isabv.url' => 'https://isabv.test/api/isabv']);
        Queue::fake();
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
        $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '11', 'days_1' => 2, 'total_quantity' => 2, 'given_quantity' => 2]);

        return $declaration;
    }

    public function test_une_prise_annulee_apres_l_arret_recompte_les_jours_meme_d_une_partie(): void
    {
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id, 'box' => 2, 'statut' => 'surv', 'perfusion' => null, 'admis_le' => now()->subDays(3)]);
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01']);
        $treatment = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Amoxicilline', 'product_id' => $produit->id, 'times' => [480], 'starts_at' => now()->subDays(2), 'ends_at' => now()]);
        $declaration = $this->declaration(['source_type' => $treatment->getMorphClass(), 'source_id' => $treatment->id]);
        $jours = [aujourdhui()->subDay()->toDateString(), aujourdhui()->toDateString()];
        $prises = array_map(fn (string $jour) => SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $treatment->id, 'jour' => $jour, 'prevu_a' => 480, 'acte' => 'Amoxicilline',
            'fait' => true, 'fait_le' => now()->subHour(), 'produit_id' => $produit->id]), $jours);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$prises[1]->id}/annuler", [], $this->enTetes($this->jeton))->assertOk();

        // Partie : une correction à 1 jour la remplace
        $correction = AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $declaration->id)->with('lines')->sole();
        $this->assertSame(1, $correction->lines[0]->days_1);
        $this->assertSame(1.0, $correction->lines[0]->total_quantity);
        $this->assertSame(DeclarationStatus::Replaced, $declaration->fresh()->status);
    }

    public function test_en_variante_2_ce_qui_n_est_pas_parti_se_dit_saisi_sur_le_site_et_ne_repart_pas(): void
    {
        $ready = $this->declaration(['status' => DeclarationStatus::Ready, 'sent_at' => null]);
        $this->postJson('/api/v1/isabv/declarations/'.$ready->id.'/entered-on-web', [], $this->enTetes($this->jeton))->assertUnprocessable();

        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_OSAV_WEB]);
        $this->postJson('/api/v1/isabv/declarations/'.$ready->id.'/entered-on-web', [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.status', 'web');

        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_VETERINARY]);
        $ready->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $this->artisan('isabv:send')->assertSuccessful();
        Queue::assertNotPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $ready->id);
    }

    public function test_desactivee_sur_le_site_seulement_pour_un_ancien_complement_et_le_complement_ne_se_recopie_pas(): void
    {
        $sameAppendix = $this->declaration(['status' => DeclarationStatus::Replaced, 'sent_appendix' => '', 'osav_message' => 'L’OSAV la tient pour un doublon.']);
        $this->postJson('/api/v1/isabv/declarations/'.$sameAppendix->id.'/deactivated-on-web', [], $this->enTetes($this->jeton))->assertUnprocessable();

        $sent = $this->declaration(['sent_appendix' => 'Site A']);
        $correction = app(Declarations::class)->update($sent, ['weight_kg' => 29]);
        $this->assertNull($correction->sent_appendix);
    }
}
