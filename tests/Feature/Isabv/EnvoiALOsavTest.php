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
use App\Services\Isabv\DeclarationXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV (08.11.2026) : l'envoi à l'OSAV (directives techniques, ch. 5 et
 * 12). Le JWT vient de l'IDE, du complément et du token ; le XML suit le
 * XSD officiel ; OK range la déclaration envoyée, NOK garde le message de
 * l'OSAV ; une réponse perdue se retrouve par le protocole, sans double
 * envoi ; une correction désactive l'ancienne avant d'envoyer la nouvelle,
 * sous un nouveau numéro.
 */
class EnvoiALOsavTest extends TestCase
{
    use EmetDesJetonsApi;
    use PrepareLeSiAbv;
    use RefreshDatabase;

    private const URL = 'https://isabv.test/api/isabv';

    private Clinique $clinique;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.isabv.url' => self::URL]);
        $this->donneesDeBaseSiAbv();
        $this->clinique = Clinique::factory()->create();
        $this->cliniqueQuiDeclare($this->clinique);
        IsabvConnection::withoutGlobalScopes()->where('clinique_id', $this->clinique->id)->update(['appendix' => 'Site de Bulle']);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->patient = Patient::factory()->for($client)->create(['espece' => 'CH']);
    }

    /** Une déclaration EH prête, née d'une ordonnance */
    private function declarationPrete(array $attributs = []): AntibioticDeclaration
    {
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'numero' => 'RX-2026-0042']);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment, 'status' => DeclarationStatus::Ready,
            'source_type' => $ordonnance->getMorphClass(), 'source_id' => $ordonnance->id, 'patient_id' => $this->patient->id,
            'visit_on' => '2026-11-08', 'species_code' => '1.5', 'weight_kg' => 32, 'diagnosis_key' => '3.7.1', ...$attributs,
        ]);
        $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7, 'total_quantity' => 14, 'given_quantity' => 20]);

        return $declaration;
    }

    private function jwtFaux(): string
    {
        return 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJDSEUtMTIzLjQ1Ni43ODkifQ.signature';
    }

    private function envoyer(AntibioticDeclaration $declaration): AntibioticDeclaration
    {
        (new SendAntibioticDeclaration($declaration->id))->handle();

        return $declaration->fresh();
    }

    public function test_l_envoi_demande_un_jwt_et_envoie_un_xml_conforme_au_xsd(): void
    {
        $declaration = $this->declarationPrete();
        Http::fake([
            self::URL.'/jwt/create' => Http::response($this->jwtFaux(), 200, ['Content-Type' => 'text/plain']),
            self::URL.'/psw/prescription/EH' => Http::response(['uuid' => 'abc', 'duplicate' => false, 'protocol' => ['uuid' => 'prot-1', 'status' => 'OK']], 201),
        ]);

        $declaration = $this->envoyer($declaration);

        $this->assertSame(DeclarationStatus::Sent, $declaration->status);
        $this->assertSame('prot-1', $declaration->osav_uuid);
        $this->assertNotNull($declaration->sent_at);
        $this->assertFalse($declaration->is_outcome_unknown);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/jwt/create'
            && $request['uid'] === 'CHE-123.456.789' && $request['token'] === 'feb3786f-c5d2-47cc-a8e0-e6d759548ff2' && $request['appendix'] === 'Site de Bulle');
        Http::assertSent(function (Request $request) use ($declaration): bool {
            if ($request->url() !== self::URL.'/psw/prescription/EH') {
                return false;
            }
            $xml = $request->body();
            $this->assertSame([], DeclarationXml::schemaErrors($xml, IsabvForm::CompanionTreatment));
            $this->assertSame('Bearer '.$this->jwtFaux(), $request->header('Authorization')[0]);
            $this->assertStringContainsString('<recipeNumber>'.$declaration->recipe_number.'</recipeNumber>', $xml);
            $this->assertStringContainsString('<veterinaryPracticeUID>CHE-123.456.789</veterinaryPracticeUID>', $xml);
            $this->assertStringContainsString('<appendix>Site de Bulle</appendix>', $xml);
            $this->assertStringContainsString('<veterinaryPracticeAddress>Clinique des Deux Rives, Rue du Pont 4, 1700 Fribourg</veterinaryPracticeAddress>', $xml);
            $this->assertStringContainsString('<dateOfVisit>2026-11-08T00:00:00</dateOfVisit>', $xml);
            $this->assertStringContainsString('<totalDosis>14</totalDosis><givenAmount>20</givenAmount>', $xml);
            $this->assertStringContainsString('<reference>RX-2026-0042</reference>', $xml);
            // Facultatifs hors de la liste des données : absents
            $this->assertStringNotContainsString('<postcode>', $xml);
            $this->assertStringNotContainsString('<antibiogramPerformed>', $xml);

            return true;
        });
    }

    public function test_un_refus_garde_le_message_de_l_osav_et_la_correction_part_sous_un_nouveau_numero(): void
    {
        Queue::fake();
        $declaration = $this->declarationPrete();
        $first = $declaration->recipe_number;
        Http::fake([
            self::URL.'/jwt/create' => Http::response($this->jwtFaux()),
            self::URL.'/psw/prescription/EH' => Http::response(['status' => 'NOK', 'message' => ['de' => 'Diagnoseschlüssel fehlt', 'fr' => 'Clé de diagnostic : la valeur manque.', 'it' => '…']], 400),
        ]);

        $declaration = $this->envoyer($declaration);

        $this->assertSame(DeclarationStatus::Rejected, $declaration->status);
        $this->assertSame('Clé de diagnostic : la valeur manque.', $declaration->osav_message);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $this->getJson('/api/v1/isabv/declarations/'.$declaration->id, $this->enTetes($jeton))
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.osav_message', 'Clé de diagnostic : la valeur manque.');

        $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, ['isabv' => ['diagnosis_key' => '3.7']], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.osav_message', null);
        $this->assertNotSame($first, $declaration->fresh()->recipe_number);
        Queue::assertPushed(SendAntibioticDeclaration::class);
    }

    public function test_une_reponse_perdue_se_retrouve_par_le_protocole_sans_double_envoi(): void
    {
        $declaration = $this->declarationPrete();
        Http::fakeSequence(self::URL.'/jwt/create')->whenEmpty(Http::response($this->jwtFaux()));
        Http::fake([self::URL.'/psw/prescription/EH' => fn () => throw new ConnectionException('délai dépassé')]);

        $declaration = $this->envoyer($declaration);
        $this->assertSame(DeclarationStatus::Ready, $declaration->status);
        $this->assertTrue($declaration->is_outcome_unknown);

        // L'OSAV l'avait reçue : le protocole le dit, rien ne repart
        Http::fake([
            self::URL.'/jwt/create' => Http::response($this->jwtFaux()),
            self::URL.'/psw/protocol' => Http::response([['uuid' => 'prot-9', 'prescriptionId' => $declaration->recipe_number, 'createdOn' => '2026-11-08T10:00:00', 'status' => 'OK']]),
            self::URL.'/psw/prescription/EH' => Http::response([], 500),
        ]);
        $declaration = $this->envoyer($declaration);

        $this->assertSame(DeclarationStatus::Sent, $declaration->status);
        $this->assertSame('prot-9', $declaration->osav_uuid);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::URL.'/psw/prescription/EH');
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/psw/protocol' && $request['prescriptionID'] === $declaration->recipe_number);
    }

    public function test_une_declaration_envoyee_corrigee_est_desactivee_puis_remplacee(): void
    {
        Queue::fake();
        $declaration = $this->declarationPrete(['status' => DeclarationStatus::Sent, 'sent_at' => now()]);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $nouvelle = $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, ['isabv' => ['weight_kg' => 31]], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.status', 'ready')->json('data');

        $this->assertNotSame($declaration->id, $nouvelle['id']);
        $this->assertNotSame($declaration->recipe_number, $nouvelle['recipe_number']);
        $this->assertSame(DeclarationStatus::Replaced, $declaration->fresh()->status);
        $remplacante = AntibioticDeclaration::withoutGlobalScopes()->find($nouvelle['id']);
        $this->assertSame($declaration->id, $remplacante->replaces_id);
        $this->assertSame(31.0, $remplacante->weight_kg);
        $this->assertCount(1, $remplacante->lines);

        // L'envoi de la nouvelle attend la désactivation de l'ancienne
        Http::fake([
            self::URL.'/jwt/create' => Http::response($this->jwtFaux()),
            self::URL.'/psw/prescription/deactivate/*' => Http::response(['status' => 'OK']),
            self::URL.'/psw/prescription/EH' => Http::response(['protocol' => ['status' => 'OK']], 201),
        ]);
        $this->assertSame(DeclarationStatus::Ready, $this->envoyer($remplacante)->status);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/psw/prescription/EH'));

        $this->assertSame(DeclarationStatus::Deactivated, $this->envoyer($declaration)->status);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/psw/prescription/deactivate/'.$declaration->recipe_number);
        $this->assertSame(DeclarationStatus::Sent, $this->envoyer($remplacante)->status);

        // Remplacée, elle ne se modifie plus
        $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, ['isabv' => ['weight_kg' => 30]], $this->enTetes($jeton))->assertUnprocessable();
    }

    public function test_en_variante_2_ou_sans_token_accepte_rien_ne_part(): void
    {
        $declaration = $this->declarationPrete();
        Http::fake();
        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_OSAV_WEB]);
        $this->assertSame(DeclarationStatus::Ready, $this->envoyer($declaration)->status);
        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_VETERINARY, 'verified_at' => null]);
        $this->assertSame(DeclarationStatus::Ready, $this->envoyer($declaration)->status);

        Http::assertNothingSent();
    }

    public function test_un_token_refuse_attend_d_etre_recolle(): void
    {
        $declaration = $this->declarationPrete();
        Http::fake([self::URL.'/jwt/create' => Http::response('', 401)]);

        $this->assertSame(DeclarationStatus::Ready, $this->envoyer($declaration)->status);

        $this->assertNull(IsabvConnection::withoutGlobalScopes()->sole()->verified_at);
    }

    public function test_une_ordonnance_annulee_entre_temps_n_envoie_rien(): void
    {
        $declaration = $this->declarationPrete();
        Ordonnance::withoutGlobalScopes()->whereKey($declaration->source_id)->delete();
        Http::fake();

        (new SendAntibioticDeclaration($declaration->id))->handle();

        $this->assertNull(AntibioticDeclaration::withoutGlobalScopes()->find($declaration->id));
        Http::assertNothingSent();
    }

    public function test_les_xml_des_quatre_formulaires_suivent_les_xsd_de_l_osav(): void
    {
        $base = ['clinique_id' => $this->clinique->id, 'status' => DeclarationStatus::Ready, 'visit_on' => '2026-11-08'];
        $cases = [
            [IsabvForm::CompanionTreatment, ['species_code' => '1.5', 'weight_kg' => 12.5, 'diagnosis_key' => '3', 'is_antibiogram_done' => true]],
            [IsabvForm::LivestockTreatment, ['species_code' => '2.1.5', 'diagnosis_key' => '2.1', 'holding_number' => '1234565', 'holding_address' => 'Ferme du Moulin, 1630 Bulle', 'animals_count' => 3, 'animals_total' => 40]],
            [IsabvForm::StockSupply, ['species_code' => '2.1.5', 'diagnosis_key' => '2.1', 'holding_number' => '1234565', 'holding_address' => 'Ferme du Moulin, 1630 Bulle', 'animals_count' => 4, 'av_treatment_days' => 5]],
            [IsabvForm::ZooLab, ['species_code' => '5.2.1', 'holding_address' => 'Zoo de la Garenne, 1261 Le Vaud']],
        ];
        foreach ($cases as [$form, $attributs]) {
            $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([...$base, 'recipe_number' => (string) Str::uuid(), 'form' => $form, ...$attributs]);
            $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => $form === IsabvForm::LivestockTreatment ? '7' : '12', 'days_1' => 7,
                'dose_2' => $form === IsabvForm::CompanionTreatment ? 0.5 : null, 'frequency_2' => $form === IsabvForm::CompanionTreatment ? '11' : null, 'days_2' => $form === IsabvForm::CompanionTreatment ? 3 : null,
                'total_quantity' => 15.5, 'given_quantity' => 20, 'is_critical_confirmed' => true]);
            $xml = (new DeclarationXml)->build($declaration->load('lines'), $this->clinique->load('societe'), null, 'RX-1');

            $this->assertSame([], DeclarationXml::schemaErrors($xml, $form), $form->value."\n".$xml);
        }
        // Un XML qui ne suit pas le schéma est relevé
        $this->assertNotSame([], DeclarationXml::schemaErrors('<EH xmlns="'.IsabvForm::CompanionTreatment->namespace().'"><dateOfVisit>hier</dateOfVisit></EH>', IsabvForm::CompanionTreatment));
    }
}
