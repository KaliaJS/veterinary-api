<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\Stock;
use App\Services\Isabv\Declarations;
use App\Services\Site\PublicShop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV, suites de la troisième vérification (08.11.2026) : une remplacée
 * annulée se désactive ; personne ne laisse d'orpheline ; la quantité remise
 * suit la totale tant qu'elle n'est pas saisie ; une voie se vide ; un soin
 * ponctuel d'hospitalisation se déclare ; une seule tâche en file ; la
 * vitrine ne montre pas d'antibiotique ; l'échéance du 20 s'annonce.
 */
class TroisiemePasseTest extends TestCase
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
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment, 'status' => DeclarationStatus::ToComplete,
            'source_type' => $ordonnance->getMorphClass(), 'source_id' => $ordonnance->id, 'patient_id' => $this->patient->id,
            'visit_on' => aujourdhui(), 'species_code' => '1.5', 'weight_kg' => 30, 'diagnosis_key' => '3', ...$attributs,
        ]);
        $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7, 'total_quantity' => 14, 'given_quantity' => 14]);

        return $declaration;
    }

    private function antibiotique(array $attributs = []): Produit
    {
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01', 'est_sur_ordonnance' => false,
            'est_stupefiant' => false, 'dose_unit' => 'cp', 'content_per_unit' => 20, ...$attributs]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 30]);

        return $produit;
    }

    public function test_une_remplacee_pas_encore_desactivee_se_desactive_si_sa_source_est_annulee(): void
    {
        Queue::fake();
        $declaration = $this->declaration(['status' => DeclarationStatus::Replaced, 'sent_at' => now()]);

        app(Declarations::class)->cancelFor(Ordonnance::withoutGlobalScopes()->find($declaration->source_id));

        $this->assertSame(DeclarationStatus::Replaced, $declaration->fresh()?->status);
        Queue::assertPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $declaration->id);
    }

    public function test_annulee_pendant_l_envoi_et_refusee_rien_n_est_a_desactiver(): void
    {
        Queue::fake();
        $declaration = $this->declaration(['status' => DeclarationStatus::Ready]);
        Http::fake([
            self::URL.'/jwt/create' => Http::response('a.b.c'),
            self::URL.'/psw/prescription/EH' => function () use ($declaration) {
                app(Declarations::class)->cancelFor(Ordonnance::withoutGlobalScopes()->find($declaration->source_id));

                return Http::response(['status' => 'NOK', 'message' => ['fr' => 'Refusée']], 400);
            },
        ]);

        (new SendAntibioticDeclaration($declaration->id))->handle();

        $this->assertSame(DeclarationStatus::Deactivated, $declaration->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/deactivate/'));
    }

    public function test_un_antibiotique_en_caisse_demande_le_droit_de_prescrire_et_une_orpheline_compte_chez_qui_prescrit(): void
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id]);
        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['produit_id' => $this->antibiotique()->id], $this->enTetes($asv))->assertForbidden();

        $this->declaration(['employe_id' => null]);
        $group = collect($this->withToken($this->jeton)->getJson('/api/v1/unfinished-work')->assertOk()->json('data.groups'))->firstWhere('kind', 'antibiotic_declaration');
        $this->assertSame(1, $group['count']);
    }

    public function test_la_remise_suit_la_totale_tant_qu_elle_n_est_pas_saisie_et_une_voie_se_vide(): void
    {
        Queue::fake();
        $declaration = $this->declaration();
        $line = $declaration->lines()->value('id');
        $put = fn (array $isabv) => $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, ['lines' => [['id' => $line, 'isabv' => $isabv]]], $this->enTetes($this->jeton));

        $put(['days_1' => 10])->assertOk()->assertJsonPath('data.lines.0.total_quantity', 20)->assertJsonPath('data.lines.0.given_quantity', 20);
        $put(['given_quantity' => 25])->assertOk()->assertJsonPath('data.lines.0.given_quantity', 25)->assertJsonPath('data.lines.0.is_given_entered', true);
        $put(['days_1' => 5])->assertOk()->assertJsonPath('data.lines.0.total_quantity', 10)->assertJsonPath('data.lines.0.given_quantity', 25);
        $put(['route_code' => null])->assertOk()->assertJsonPath('data.lines.0.route_code', null);
    }

    public function test_un_soin_ponctuel_d_hospitalisation_avec_un_antibiotique_se_declare_et_s_annule(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Europe/Zurich'));
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id,
            'box' => 2, 'statut' => 'surv', 'perfusion' => null, 'admis_le' => now()->subDay()]);
        $produit = $this->antibiotique();
        $soin = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 600, 'acte' => 'Amoxicilline', 'produit_id' => $produit->id, 'dose_amount' => 1]);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/Zurich'));

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$soin->id}/faire", [], $this->enTetes($this->jeton))->assertOk();

        $declaration = AntibioticDeclaration::withoutGlobalScopes()->with('lines')->sole();
        $this->assertSame([1.0, '11', 1, 1.0], [$declaration->lines[0]->dose_1, $declaration->lines[0]->frequency_1, $declaration->lines[0]->days_1, $declaration->lines[0]->total_quantity]);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$soin->id}/annuler", [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(0, AntibioticDeclaration::withoutGlobalScopes()->count());
    }

    public function test_une_seule_tache_en_file_par_declaration(): void
    {
        Queue::fake();
        $declaration = $this->declaration(['status' => DeclarationStatus::Ready]);
        $declaration->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

        $this->artisan('isabv:send')->assertSuccessful();
        $this->artisan('isabv:send')->assertSuccessful();

        Queue::assertPushed(SendAntibioticDeclaration::class, 1);
    }

    public function test_la_vitrine_ne_montre_pas_un_antibiotique_de_la_liste(): void
    {
        $catalogue = ProduitVeterinaire::query()->create(['numero_autorisation' => '12345', 'nom' => 'Amoxicilline', 'nom_court' => 'Amoxicilline', 'categorie' => 'medicament']);
        ProduitEmballage::query()->create(['produit_id' => $catalogue->id, 'numero_emballage' => '12345001', 'sequence' => '1', 'gtin' => '7680123450016']);
        $parLien = $this->antibiotique(['est_boutique' => true]);
        $parCodeBarres = $this->antibiotique(['est_boutique' => true, 'isabv_preparation_code' => null, 'ean' => '7680123450016']);
        $croquettes = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'categorie' => 'ali', 'est_boutique' => true, 'est_sur_ordonnance' => false, 'est_stupefiant' => false]);

        $ids = app(PublicShop::class)->articlesQuery($this->clinique->societe->organisation)->pluck('id')->all();

        $this->assertContains($croquettes->id, $ids);
        $this->assertNotContains($parLien->id, $ids);
        $this->assertNotContains($parCodeBarres->id, $ids);
    }

    public function test_l_echeance_du_20_s_annonce_a_qui_prescrit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Europe/Zurich'));
        ['jeton' => $veterinaire] = $this->employeAvecJeton($this->clinique);
        $this->declaration(['visit_on' => '2026-10-05']);
        $alertes = fn (string $jeton) => collect($this->withToken($jeton)->getJson('/api/v1/alertes')->assertOk()->json('data.alertes'))->where('type', 'isabv')->values();

        $alerte = $alertes($veterinaire)->sole();
        $this->assertSame('warn', $alerte['niveau']);
        $this->assertSame('1 déclaration SI ABV à faire partir avant le 20.11.2026', $alerte['titre']);
        $this->assertSame('antibiotic-declarations', $alerte['cible']);
        ['jeton' => $assistant] = $this->employeAvecJeton($this->clinique, [], 'assistant');
        $this->assertCount(0, $alertes($assistant));

        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:00', 'Europe/Zurich'));
        $this->assertSame('urgent', $alertes($veterinaire)->sole()['niveau']);
    }
}
