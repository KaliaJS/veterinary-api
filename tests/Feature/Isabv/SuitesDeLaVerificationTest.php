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
use App\Models\IsabvConnection;
use App\Models\IsabvConsultationReport;
use App\Models\LignePlanning;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use App\Models\Stock;
use App\Services\Isabv\Declarations;
use App\Services\Isabv\DeclarationXml;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV, suites de la vérification du 08.11.2026 : l'antibiotique remis en
 * caisse et celui utilisé pendant une hospitalisation se déclarent ; une
 * correction n'en crée jamais deux, et l'envoyée reste active tant que la
 * correction n'est pas complète ; une déclaration prise par l'envoi ne se
 * modifie pas ; un traitement oral de groupe se dit saisi sur le site de
 * l'OSAV ; ce qui attend repart ; le nombre de consultations part seul en
 * février et se corrige ; qui prescrit voit la variante sans le token.
 */
class SuitesDeLaVerificationTest extends TestCase
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
        $this->patient->pesees()->create(['poids_kg' => 30, 'pesee_le' => now()->subDay()->toDateString()]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function antibiotique(string $doseUnit = 'cp'): Produit
    {
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01', 'est_sur_ordonnance' => true,
            'est_stupefiant' => false, 'dose_unit' => $doseUnit, 'content_per_unit' => 20]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 30]);

        return $produit;
    }

    private function envoyee(array $attributs = []): AntibioticDeclaration
    {
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment, 'status' => DeclarationStatus::Sent, 'sent_at' => now(),
            'source_type' => $ordonnance->getMorphClass(), 'source_id' => $ordonnance->id, 'patient_id' => $this->patient->id,
            'visit_on' => aujourdhui(), 'species_code' => '1.5', 'weight_kg' => 30, 'diagnosis_key' => '3', ...$attributs,
        ]);
        $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7, 'total_quantity' => 14, 'given_quantity' => 14]);

        return $declaration;
    }

    public function test_un_antibiotique_ajoute_a_une_facture_se_declare_et_s_efface_avec_sa_ligne(): void
    {
        Queue::fake();
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id]);
        $produit = $this->antibiotique();

        $lignes = $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['produit_id' => $produit->id], $this->enTetes($this->jeton))->assertOk()->json('data.lignes');

        $declaration = AntibioticDeclaration::withoutGlobalScopes()->with('lines')->sole();
        $this->assertSame(DeclarationStatus::ToComplete, $declaration->status);
        $this->assertSame('12345-01', $declaration->lines[0]->preparation_code);
        $this->assertSame($this->patient->id, $declaration->patient_id);
        $ligne = collect($lignes)->firstWhere('produit_id', $produit->id)['id'];

        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne}", [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(0, AntibioticDeclaration::withoutGlobalScopes()->count());
    }

    public function test_un_antibiotique_de_l_hospitalisation_se_declare_et_ses_jours_viennent_a_l_arret(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Europe/Zurich'));
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id,
            'box' => 2, 'statut' => 'surv', 'perfusion' => null, 'admis_le' => now()->subDay()]);
        $produit = $this->antibiotique('cp');

        $traitement = $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/traitements", [
            'label' => 'Amoxicilline', 'product_id' => $produit->id, 'dose_amount' => 1, 'times' => [480, 1200],
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $declaration = AntibioticDeclaration::withoutGlobalScopes()->with('lines')->sole();
        $this->assertSame(DeclarationStatus::ToComplete, $declaration->status);
        // 2 prises par jour : « 2 administrations / jour » ; 1 cp vaut 1 pièce de l'OSAV
        $this->assertSame('12', $declaration->lines[0]->frequency_1);
        $this->assertSame(1.0, $declaration->lines[0]->dose_1);
        $this->assertSame([(new StayTreatment)->getMorphClass(), $traitement['id']], [$declaration->source_type, $declaration->source_id]);

        foreach (['2026-11-03', '2026-11-04'] as $jour) {
            SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement['id'], 'jour' => $jour, 'prevu_a' => 480, 'acte' => 'Amoxicilline', 'fait' => true, 'fait_le' => now()]);
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-04 21:00', 'Europe/Zurich'));
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/traitements/{$traitement['id']}/arreter", [], $this->enTetes($this->jeton))->assertOk();

        $line = $declaration->fresh('lines')->lines[0];
        $this->assertSame(2, $line->days_1);
        // 1 pièce, 2 fois par jour, 2 jours
        $this->assertSame(4.0, $line->total_quantity);
        $this->assertTrue(Declarations::isSameUnit('ml', 'ml'));
        $this->assertFalse(Declarations::isSameUnit('ml', 'Stück'));
    }

    public function test_une_correction_n_en_cree_jamais_deux_et_l_envoyee_reste_active_tant_qu_elle_est_incomplete(): void
    {
        Queue::fake();
        $declaration = $this->envoyee();
        $line = $declaration->lines()->value('id');

        // Une correction incomplète (le jour vidé), deux fois : une seule remplaçante, l'envoyée reste « envoyée »
        $put = fn (array $body) => $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, $body, $this->enTetes($this->jeton));
        $first = $put(['lines' => [['id' => $line, 'isabv' => ['days_1' => null]]]])->assertOk()->assertJsonPath('data.status', 'to_complete')->json('data.id');
        $second = $put(['isabv' => ['weight_kg' => 29]])->assertOk()->json('data.id');
        $this->assertSame($first, $second);
        $this->assertSame(2, AntibioticDeclaration::withoutGlobalScopes()->count());
        $this->assertSame(DeclarationStatus::Sent, $declaration->fresh()->status);
        Queue::assertNotPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $declaration->id);

        // Complétée : l'envoyée passe à désactiver, puis la nouvelle part
        $put(['lines' => [['id' => $line, 'isabv' => ['days_1' => 5]]]])->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertSame(DeclarationStatus::Replaced, $declaration->fresh()->status);
        $this->assertSame(5, AntibioticDeclaration::withoutGlobalScopes()->find($first)->lines()->value('days_1'));
        Queue::assertPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $declaration->id);
    }

    public function test_une_declaration_prise_par_l_envoi_ne_se_modifie_pas_et_l_envoi_relit_apres_la_prise(): void
    {
        $declaration = $this->envoyee(['status' => DeclarationStatus::Ready, 'sent_at' => null, 'is_outcome_unknown' => true]);
        $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, ['isabv' => ['weight_kg' => 29]], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('declaration');

        // Prête, non prise : la tâche la prend, puis envoie ce qu'elle relit
        $declaration->update(['is_outcome_unknown' => false, 'weight_kg' => 31]);
        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/prescription/EH' => Http::response(['protocol' => ['status' => 'OK']], 201)]);
        (new SendAntibioticDeclaration($declaration->id))->handle();
        $this->assertSame(DeclarationStatus::Sent, $declaration->fresh()->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/EH') && str_contains($request->body(), '<animalWeight>31</animalWeight>'));
    }

    public function test_l_antibiogramme_fait_part_et_l_annulation_d_une_envoyee_la_desactive(): void
    {
        Queue::fake();
        $declaration = $this->envoyee(['is_antibiogram_done' => true]);
        $xml = (new DeclarationXml)->build($declaration->load('lines'), $this->clinique->load('societe'), null);
        $this->assertStringContainsString('<antibiogramPerformed>true</antibiogramPerformed>', $xml);
        $this->assertSame([], DeclarationXml::schemaErrors($xml, IsabvForm::CompanionTreatment));

        app(Declarations::class)->cancelFor(Ordonnance::withoutGlobalScopes()->find($declaration->source_id));

        $this->assertSame(DeclarationStatus::Replaced, $declaration->fresh()->status);
        Queue::assertPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $declaration->id);
    }

    public function test_un_traitement_oral_de_groupe_se_dit_saisi_sur_le_site_de_l_osav(): void
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'holding_number' => '7654321', 'rue' => 'Porcherie', 'npa' => '1630', 'localite' => 'Bulle']);
        $porc = Patient::factory()->for($client)->create(['espece' => 'PO', 'race' => null, 'isabv_species' => '2.2.3']);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::LivestockTreatment,
            'status' => DeclarationStatus::ToComplete, 'patient_id' => $porc->id, 'visit_on' => aujourdhui(), 'species_code' => '2.2.3', 'diagnosis_key' => '5.1', 'animals_count' => 30,
            'holding_number' => '7654321', 'holding_address' => 'Porcherie, 1630 Bulle']);
        $line = $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => null, 'dose_1' => 1, 'frequency_1' => '6', 'days_1' => 3, 'total_quantity' => 90, 'given_quantity' => 90]);

        // Sans voie, un groupe ne dit pas s'il est oral : elle se demande
        $this->getJson('/api/v1/isabv/declarations/'.$declaration->id, $this->enTetes($this->jeton))->assertJsonPath('data.missing', ['la voie d’administration (traitement de groupe)']);
        $this->postJson('/api/v1/isabv/declarations/'.$declaration->id.'/entered-on-web', [], $this->enTetes($this->jeton))->assertUnprocessable();

        $line->update(['route_code' => 'po']);
        $this->postJson('/api/v1/isabv/declarations/'.$declaration->id.'/entered-on-web', [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.status', 'web');
        $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, ['isabv' => ['animals_count' => 5]], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_termine_a_compte_les_refusees_et_les_pas_encore_parties(): void
    {
        ['jeton' => $jeton, 'employe' => $employe] = $this->employeAvecJeton($this->clinique);
        foreach ([DeclarationStatus::Rejected, DeclarationStatus::Ready, DeclarationStatus::Sent] as $status) {
            $this->envoyee(['status' => $status, 'employe_id' => $employe->id]);
        }

        $group = collect($this->withToken($jeton)->getJson('/api/v1/unfinished-work')->assertOk()->json('data.groups'))->firstWhere('kind', 'antibiotic_declaration');

        $this->assertSame(2, $group['count']);
        $this->assertEqualsCanonicalizing(['Refusée par l’OSAV', 'Pas encore partie'], array_column($group['lines'], 'label'));
    }

    public function test_ce_qui_attend_repart_seulement_pour_une_clinique_qui_declare(): void
    {
        Queue::fake();
        $old = $this->envoyee(['status' => DeclarationStatus::Ready, 'sent_at' => null]);
        $old->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
        $fresh = $this->envoyee(['status' => DeclarationStatus::Ready, 'sent_at' => null]);

        $this->artisan('isabv:send')->assertSuccessful();
        Queue::assertPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $old->id);
        Queue::assertNotPushed(SendAntibioticDeclaration::class, fn ($job) => $job->declarationId === $fresh->id);

        Queue::fake();
        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_OSAV_WEB]);
        $this->artisan('isabv:send')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_le_nombre_de_consultations_part_seul_se_renvoie_et_ne_compte_pas_les_teleconsultations(): void
    {
        $year = (int) now('Europe/Zurich')->year - 1;
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        foreach (['consult', 'tele'] as $type) {
            RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'ligne_planning_id' => $ligne->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id,
                'species' => 'CH', 'type' => $type, 'status' => 'fini', 'starts_at' => "$year-05-05 08:00:00", 'ends_at' => "$year-05-05 08:30:00"]);
        }
        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/saveConsultation' => Http::response(['status' => 'OK'], 201)]);

        $this->artisan('isabv:send-consultations')->assertSuccessful();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/saveConsultation') && $request['animalType'] === '1.5' && $request['consultationPerYear'] === 1 && $request['year'] === $year);
        $this->assertSame(4, IsabvConsultationReport::withoutGlobalScopes()->count());

        // Rien n'a changé : rien ne repart ; renvoyé à la main, il remplace (toujours 4 lignes)
        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/saveConsultation' => Http::response(['status' => 'OK'], 200)]);
        $this->artisan('isabv:send-consultations')->assertSuccessful();
        Http::assertNothingSent();
        $this->postJson('/api/v1/isabv/consultations', ['year' => $year], $this->enTetes($this->jeton))->assertOk();
        Http::assertSentCount(5);
        $this->assertSame(4, IsabvConsultationReport::withoutGlobalScopes()->count());
    }

    public function test_qui_prescrit_voit_la_variante_sans_le_token(): void
    {
        $this->getJson('/api/v1/isabv/connection', $this->enTetes($this->jeton))->assertOk()->assertExactJson(['data' => ['variant' => 1, 'is_sending' => true]]);
        $this->getJson('/api/v1/isabv/connection', $this->enTetes($this->administrateurAvecJeton($this->clinique)['jeton']))->assertOk()->assertJsonPath('data.token_end', '8ff2');
    }

    public function test_un_doublon_ecarte_par_l_osav_se_dit(): void
    {
        $declaration = $this->envoyee(['status' => DeclarationStatus::Ready, 'sent_at' => null]);
        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/prescription/EH' => Http::response(['duplicate' => true, 'protocol' => ['status' => 'OK']], 201)]);

        (new SendAntibioticDeclaration($declaration->id))->handle();

        $this->assertSame(DeclarationStatus::Sent, $declaration->fresh()->status);
        $this->assertStringContainsString('doublon', (string) $declaration->fresh()->osav_message);
    }
}
