<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Models\AntibioticDeclaration;
use App\Models\CatalogueImport;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\IsabvCode;
use App\Models\IsabvConnection;
use App\Models\IsabvConsultationReport;
use App\Models\IsabvPreparation;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Services\Isabv\ImportMasterData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV (08.11.2026) : le réglage de la clinique (variante, complément,
 * token vérifié par l'OSAV et jamais servi), le nombre de consultations par
 * espèce et par année (O-SI ABV, annexe ch. 2.2.1.5), les doublons
 * réactivables, le faux serveur local, les données de base de l'OSAV et
 * « Terminé à ».
 */
class ReglagesEtConsultationsTest extends TestCase
{
    use EmetDesJetonsApi;
    use PrepareLeSiAbv;
    use RefreshDatabase;

    private const URL = 'https://isabv.test/api/isabv';

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.isabv.url' => self::URL, 'services.isabv.master_data_url' => 'https://osav.test/psw/getMasterData']);
        $this->donneesDeBaseSiAbv();
        $this->clinique = Clinique::factory()->create();
        $this->clinique->societe->update(['ide' => 'CHE-123.456.789']);
    }

    private function admin(): array
    {
        return $this->enTetes($this->administrateurAvecJeton($this->clinique)['jeton']);
    }

    public function test_le_token_est_verifie_par_l_osav_garde_chiffre_et_jamais_servi(): void
    {
        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c')]);

        $data = $this->putJson('/api/v1/isabv/connection', ['variant' => 1, 'appendix' => 'Site de Bulle', 'token' => 'feb3786f-c5d2-47cc-a8e0-e6d759548ff2'], $this->admin())
            ->assertOk()->json('data');

        $this->assertSame(['variant' => 1, 'appendix' => 'Site de Bulle', 'uid' => 'CHE-123.456.789', 'has_token' => true, 'token_end' => '8ff2', 'is_sending' => true], array_intersect_key($data, array_flip(['variant', 'appendix', 'uid', 'has_token', 'token_end', 'is_sending'])));
        $this->assertStringNotContainsString('feb3786f', json_encode($data));
        $connection = IsabvConnection::withoutGlobalScopes()->sole();
        $this->assertNotSame('feb3786f-c5d2-47cc-a8e0-e6d759548ff2', $connection->getRawOriginal('token'));
        $this->assertSame('feb3786f-c5d2-47cc-a8e0-e6d759548ff2', Crypt::decryptString($connection->getRawOriginal('token')));
        Http::assertSent(fn (Request $request) => $request['uid'] === 'CHE-123.456.789' && $request['appendix'] === 'Site de Bulle');
        $this->assertArrayNotHasKey('token', $connection->toArray());
    }

    public function test_un_token_refuse_un_complement_interdit_ou_sans_ide_ne_s_enregistre_pas(): void
    {
        Http::fake([self::URL.'/jwt/create' => Http::response('', 401)]);
        $this->putJson('/api/v1/isabv/connection', ['variant' => 1, 'token' => 'mauvais-token-123'], $this->admin())
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);
        $this->putJson('/api/v1/isabv/connection', ['variant' => 1, 'appendix' => 'A&B', 'token' => 'feb3786f-c5d2'], $this->admin())
            ->assertUnprocessable()->assertJsonValidationErrors(['appendix']);
        $this->clinique->societe->update(['ide' => null]);
        $this->putJson('/api/v1/isabv/connection', ['variant' => 1, 'token' => 'feb3786f-c5d2'], $this->admin())
            ->assertUnprocessable()->assertJsonValidationErrors(['variant']);
        $this->assertSame(0, IsabvConnection::withoutGlobalScopes()->count());

        // La variante 2 ne demande rien à l'OSAV
        $this->putJson('/api/v1/isabv/connection', ['variant' => 2], $this->admin())->assertOk()->assertJsonPath('data.is_sending', false);
    }

    public function test_le_reglage_demande_le_droit_organisation(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'assistant');
        $this->putJson('/api/v1/isabv/connection', ['variant' => 2], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_le_nombre_de_consultations_est_compte_par_espece_dans_l_annee(): void
    {
        $this->cliniqueQuiDeclare($this->clinique);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $rdv = function (string $espece, string $quand, array $attributs = []) use ($client, $ligne): void {
            $patient = Patient::factory()->for($client)->create(['espece' => $espece, 'race' => null, 'isabv_species' => $attributs['isabv_species'] ?? null]);
            RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'ligne_planning_id' => $ligne->id, 'patient_id' => $patient->id, 'client_id' => $client->id,
                'species' => $espece, 'type' => $attributs['type'] ?? 'consult', 'status' => $attributs['status'] ?? 'fini', 'starts_at' => $quand, 'ends_at' => $quand]);
        };
        $year = (int) now('Europe/Zurich')->year - 1;
        $rdv('CH', "$year-03-01 10:00:00");
        $rdv('CH', "$year-06-01 10:00:00");
        $rdv('CT', "$year-06-01 10:00:00");
        $rdv('CV', "$year-06-01 10:00:00");
        $rdv('LP', "$year-06-01 10:00:00");
        // Pas comptés : une vache, un lapin de rente, un rendez-vous non fini, une plage tampon, l'année d'après
        $rdv('BV', "$year-06-01 10:00:00");
        $rdv('LP', "$year-06-01 10:00:00", ['isabv_species' => '2.1.5']);
        $rdv('CH', "$year-06-01 10:00:00", ['status' => 'noshow']);
        $rdv('CH', "$year-06-01 10:00:00", ['type' => 'tampon']);
        // Le 31 décembre à 23 h 30 à Zurich est encore dans l'année
        $rdv('CH', "$year-12-31 22:30:00");
        $rdv('CH', ($year + 1).'-01-01 00:30:00');

        $years = $this->getJson('/api/v1/isabv/consultations', $this->admin())->assertOk()->json('data');

        $this->assertSame($year, $years[0]['year']);
        $this->assertSame(($year + 1).'-02-20', $years[0]['deadline']);
        $this->assertSame(['1.5' => 3, '1.4' => 1, '1.1' => 1, '1.11' => 1], array_column($years[0]['species'], 'count', 'code'));

        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/saveConsultation' => Http::response(['status' => 'OK'], 201)]);
        $this->postJson('/api/v1/isabv/consultations', ['year' => $year], $this->admin())->assertOk();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/psw/saveConsultation') && $request['year'] === $year && $request['animalType'] === '1.5' && $request['consultationPerYear'] === 3);
        $this->assertSame(4, IsabvConsultationReport::withoutGlobalScopes()->where('status', 'sent')->count());
        // Seules l'année en cours et l'année écoulée sont acceptées par l'OSAV
        $this->postJson('/api/v1/isabv/consultations', ['year' => $year - 1], $this->admin())->assertUnprocessable();
    }

    public function test_les_doublons_ecartes_se_listent_et_se_reactivent(): void
    {
        $this->cliniqueQuiDeclare($this->clinique);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::StockSupply,
            'status' => DeclarationStatus::Sent, 'visit_on' => aujourdhui(), 'species_code' => '2.1.5']);
        Http::fake([
            self::URL.'/jwt/create' => Http::response('a.b.c'),
            self::URL.'/psw/prescription/getDuplicatePrescriptions' => Http::response(['status' => 'OK', 'prescriptionIDs' => [$declaration->recipe_number, 'inconnue']]),
            self::URL.'/psw/prescription/reactivateDuplicatePrescriptions' => Http::response(['status' => 'OK']),
        ]);

        $this->getJson('/api/v1/isabv/duplicates', $this->admin())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $declaration->id);
        $this->postJson('/api/v1/isabv/duplicates/reactivate', ['ids' => [$declaration->id]], $this->admin())->assertOk()->assertJsonPath('data.reactivated', 1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'reactivateDuplicatePrescriptions') && $request['prescriptionIDs'] === [$declaration->recipe_number] && $request['uid'] === 'CHE-123.456.789');
    }

    public function test_le_faux_serveur_local_valide_le_xsd_et_repond_comme_l_osav(): void
    {
        $jwt = $this->postJson('/fake-isabv/api/isabv/jwt/create', ['uid' => 'CHE-123.456.789', 'token' => 'essai'])->assertOk()->getContent();
        $this->postJson('/fake-isabv/api/isabv/jwt/create', ['uid' => 'CHE-123.456.789', 'token' => 'refuse-moi'])->assertUnauthorized();
        $ns = IsabvForm::CompanionTreatment->namespace();
        $recipe = (string) Str::uuid();
        $xml = '<?xml version="1.0" encoding="utf-8"?><EH xmlns="'.$ns.'"><dateOfVisit>2026-11-08T00:00:00</dateOfVisit><recipeNumber>'.$recipe.'</recipeNumber><veterinaryPracticeUID>CHE-123.456.789</veterinaryPracticeUID><veterinaryPracticeAddress>Rue 1, 1700 Fribourg</veterinaryPracticeAddress><species>1.5</species><animalWeight>12</animalWeight><diagnosisKey>3</diagnosisKey><preparations><preparation><preparationID>12345-01</preparationID><firstDosis><animalDosis>1</animalDosis><treatmentDays>7</treatmentDays><servingMode>12</servingMode></firstDosis><totalDosis>14</totalDosis><givenAmount>14</givenAmount><treatmentDays>7</treatmentDays></preparation></preparations></EH>';
        $post = fn (string $body) => $this->call('POST', '/fake-isabv/api/isabv/psw/prescription/EH', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt, 'CONTENT_TYPE' => 'application/xml'], $body);

        $post($xml)->assertCreated()->assertJsonPath('protocol.status', 'OK');
        // Le même numéro une seconde fois, puis un XML qui ne suit pas le schéma : NOK
        $post($xml)->assertStatus(400)->assertJsonPath('status', 'NOK');
        $post(str_replace('<animalWeight>12</animalWeight>', '', str_replace($recipe, (string) Str::uuid(), $xml)))->assertStatus(400)->assertJsonPath('status', 'NOK');
        $this->call('POST', '/fake-isabv/api/isabv/psw/prescription/EH', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $xml)->assertUnauthorized();
    }

    public function test_les_donnees_de_base_de_l_osav_s_importent_et_ne_se_relisent_pas_sans_changement(): void
    {
        IsabvCode::query()->delete();
        IsabvPreparation::query()->delete();
        IsabvPreparation::create(['code' => '99999-01', 'name_fr' => 'Retirée', 'unit_code' => 'Stück', 'unit_label_fr' => 'pièces', 'must_transfer' => true]);
        $lists = [
            'lastModificationDate' => '2026-10-07T14:17:35',
            'species' => [['code' => '1', 'descriptionFR' => 'Animal de compagnie', 'parentSpecies' => null, 'validForConsultations' => false], ['code' => '1.5', 'descriptionFR' => 'Chien', 'descriptionDE' => 'Hund', 'parentSpecies' => '1', 'validForConsultations' => true]],
            'diagnosisKey' => [['code' => '3.7.1', 'descriptionFR' => 'Infection urinaire simple', 'species' => ['code' => '1.5'], 'parentDiagnosisKey' => '1.5-3.7']],
            'administrationMode' => [['code' => '12', 'descriptionFR' => '2 administrations / jour', 'prescriptionCategory' => 'EH', 'value' => 2.0]],
            'administrationMethod' => [['code' => 'po', 'descriptionFR' => 'oral']],
            'preparation' => [['code' => '20258-01', 'descriptionFR' => 'Erythrocin i.v., substance sèche 1000 mg', 'atcCode' => 'J01FA01', 'unit' => ['code' => 'mg', 'descriptionFR' => 'mg'],
                'preparationCategory' => ['code' => 'Inj', 'descriptionFR' => 'Injectable'], 'transferISABV' => true, 'preparationType' => 'HUMAN', 'administrationMethod' => ['code' => 'iv'], 'hasCriticalSubstance' => true]],
        ];
        Http::fake(fn (Request $request) => Http::response($lists[basename(parse_url($request->url(), PHP_URL_PATH))] ?? [], 200));

        $journal = app(ImportMasterData::class)->run();

        $this->assertSame(CatalogueImport::REUSSI, $journal->statut);
        $this->assertSame('3.7', IsabvCode::query()->where(['kind' => 'diagnosis', 'species_code' => '1.5', 'code' => '3.7.1'])->value('parent_code'));
        $this->assertSame(2.0, IsabvCode::query()->where(['kind' => 'frequency', 'code' => '12'])->sole()->per_day);
        $preparation = IsabvPreparation::query()->where('code', '20258-01')->sole();
        $this->assertTrue($preparation->must_transfer);
        $this->assertTrue($preparation->has_critical_substance);
        $this->assertSame('mg', $preparation->unit_code);
        // Retirée par l'OSAV : gardée, sans plus se déclarer
        $this->assertFalse(IsabvPreparation::query()->where('code', '99999-01')->sole()->must_transfer);

        $this->assertSame(CatalogueImport::IDENTIQUE, app(ImportMasterData::class)->run()->statut);
        Http::assertSentCount(7);
    }

    public function test_une_declaration_a_completer_compte_dans_termine_a(): void
    {
        $this->cliniqueQuiDeclare($this->clinique);
        ['jeton' => $jeton, 'employe' => $employe] = $this->employeAvecJeton($this->clinique);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment,
            'status' => DeclarationStatus::ToComplete, 'visit_on' => aujourdhui(), 'employe_id' => $employe->id]);
        AntibioticDeclaration::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment,
            'status' => DeclarationStatus::Sent, 'visit_on' => aujourdhui(), 'employe_id' => $employe->id]);

        $groups = collect($this->withToken($jeton)->getJson('/api/v1/unfinished-work')->assertOk()->json('data.groups'))->keyBy('kind');

        $this->assertSame(1, $groups['antibiotic_declaration']['count']);
        $this->assertSame(['type' => 'antibiotic_declaration', 'id' => $declaration->id], $groups['antibiotic_declaration']['lines'][0]['target']);
    }

    public function test_un_medicament_se_relie_a_la_main_a_une_preparation_de_l_osav(): void
    {
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $this->patchJson('/api/v1/stocks/produits/'.$produit->id.'/isabv', ['preparation_code' => '12345-01'], $this->admin())
            ->assertOk()->assertJsonPath('data.isabv.code', '12345-01')->assertJsonPath('data.isabv.is_declarable', true)->assertJsonPath('data.isabv.is_manual', true);
        $this->patchJson('/api/v1/stocks/produits/'.$produit->id.'/isabv', ['preparation_code' => 'inventee'], $this->admin())->assertUnprocessable();
        $this->patchJson('/api/v1/stocks/produits/'.$produit->id.'/isabv', ['preparation_code' => null], $this->admin())->assertOk()->assertJsonPath('data.isabv', null);
    }

    public function test_la_fiche_porte_l_espece_de_l_osav_et_le_detenteur_son_unite_d_elevage(): void
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $vache = Patient::factory()->for($client)->create(['espece' => 'BV', 'race' => null]);

        $this->getJson('/api/v1/patients/'.$vache->id, $this->admin())->assertJsonPath('data.isabv_species_default', '2.1');
        $this->patchJson('/api/v1/patients/'.$vache->id, ['isabv_species' => '2.1.5'], $this->admin())->assertOk()->assertJsonPath('data.isabv_species', '2.1.5');
        $this->patchJson('/api/v1/patients/'.$vache->id, ['isabv_species' => '9.9'], $this->admin())->assertUnprocessable();
        $this->patchJson('/api/v1/clients/'.$client->id, ['holding_number' => '1234565', 'holding_address' => 'Alpage du Moléson, 1663 Gruyères'], $this->admin())
            ->assertOk()->assertJsonPath('data.holding_number', '1234565')->assertJsonPath('data.holding_address', 'Alpage du Moléson, 1663 Gruyères');
    }
}
