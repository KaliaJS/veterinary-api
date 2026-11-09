<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Clinique;
use App\Models\IsabvConnection;
use App\Services\Isabv\IsabvClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * SI ABV : le vrai serveur de test de l'OSAV (isabv-a, cabinet de test
 * ouvert par l'OSAV le 09.11.2026). Chaque commande y passe par le code de
 * Veterinary (tâche d'envoi, XML, client) : EH, EN (dont un groupe non
 * oral, déclaré en traitement individuel quel que soit le nombre
 * d'animaux, réponse de l'OSAV du 09.11.2026), AV (deux des trois
 * données), FZL, désactivation, protocole, nombre de consultations,
 * doublons. Codes réels des données de base du serveur de test.
 *
 * Ne tourne que si le cabinet de test est donné, dans le .env local
 * (jamais dans le dépôt) : ISABV_TEST_UID, ISABV_TEST_APPENDIX,
 * ISABV_TEST_TOKEN (config services.isabv.test). Hors des essais
 * ordinaires : `php artisan test --group serveur-osav`.
 */
#[Group('serveur-osav')]
class ServeurDeTestOsavTest extends TestCase
{
    use RefreshDatabase;

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();
        if ((string) config('services.isabv.test.token') === '') {
            $this->markTestSkipped('Cabinet de test SI ABV non donné (ISABV_TEST_TOKEN).');
        }
        Http::preventStrayRequests(false);
        config(['services.isabv.url' => config('services.isabv.test.url')]);
        $this->clinique = Clinique::factory()->create(['nom' => 'Clinique Meteora (essai)', 'rue' => 'Rue du Test 1', 'npa' => '1700', 'ville' => 'Fribourg']);
        $this->clinique->societe->update(['ide' => (string) config('services.isabv.test.uid')]);
        IsabvConnection::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id,
            'variant' => IsabvConnection::VIA_VETERINARY,
            'appendix' => config('services.isabv.test.appendix') ?: null,
            'token' => Crypt::encryptString((string) config('services.isabv.test.token')),
            'verified_at' => now(),
        ]);
    }

    private function client(): IsabvClient
    {
        return IsabvClient::for((string) config('services.isabv.test.uid'), config('services.isabv.test.appendix') ?: null, (string) config('services.isabv.test.token'));
    }

    /** @param list<array<string, mixed>> $lines */
    private function declaration(IsabvForm $form, array $attributes, array $lines): AntibioticDeclaration
    {
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => $form, 'status' => DeclarationStatus::Ready,
            'visit_on' => now()->toDateString(), ...$attributes,
        ]);
        foreach ($lines as $line) {
            $declaration->lines()->create($line);
        }

        return $declaration;
    }

    private function sendAndExpectSent(AntibioticDeclaration $declaration): AntibioticDeclaration
    {
        (new SendAntibioticDeclaration($declaration->id))->handle();
        $declaration = $declaration->fresh();
        $this->assertSame(DeclarationStatus::Sent, $declaration->status, $declaration->form->value.' : '.(string) $declaration->osav_message);
        $this->assertNotNull($declaration->osav_uuid);

        return $declaration;
    }

    public function test_le_token_du_cabinet_de_test_donne_un_jwt(): void
    {
        $this->assertSame(2, substr_count($this->client()->jwt(), '.'));
    }

    public function test_eh_chien_part_se_lit_au_protocole_puis_se_desactive(): void
    {
        $declaration = $this->sendAndExpectSent($this->declaration(IsabvForm::CompanionTreatment, [
            'species_code' => '1.5', 'weight_kg' => 32, 'diagnosis_key' => '3.7.1', 'identification' => 'Rex (essai)',
        ], [[
            'preparation_code' => '56874-02', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7,
            'total_quantity' => 14, 'given_quantity' => 14, 'starts_on' => now()->toDateString(),
        ]]));

        $known = $this->client()->protocolFor($declaration->recipe_number);
        $this->assertNotNull($known, 'Le protocole ne connaît pas la prescription envoyée');
        $this->assertTrue($known->isOk);

        $declaration->forceFill(['status' => DeclarationStatus::Replaced])->save();
        (new SendAntibioticDeclaration($declaration->id))->handle();
        $this->assertSame(DeclarationStatus::Deactivated, $declaration->fresh()->status);
    }

    public function test_en_vache_laitiere_intramammaire_part(): void
    {
        $this->sendAndExpectSent($this->declaration(IsabvForm::LivestockTreatment, [
            'species_code' => '2.1.5', 'diagnosis_key' => '2.1.1.4', 'identification' => 'CH 120.1234.5678.9',
            'holding_number' => '1234565', 'holding_address' => 'Ferme du Test, 1700 Fribourg', 'animals_count' => 1,
        ], [[
            'preparation_code' => '59449-01', 'route_code' => 'mm', 'dose_1' => 1, 'frequency_1' => '6', 'days_1' => 2,
            'total_quantity' => 2, 'given_quantity' => 2, 'starts_on' => now()->toDateString(),
        ]]));
    }

    public function test_en_groupe_non_oral_de_porcs_au_dela_de_la_taille_de_groupe_part(): void
    {
        $this->sendAndExpectSent($this->declaration(IsabvForm::LivestockTreatment, [
            'species_code' => '2.2.3', 'diagnosis_key' => '5.1.99', 'holding_number' => '1234565', 'holding_address' => 'Porcherie du Test, 1630 Bulle',
            'animals_count' => 45, 'animals_total' => 120,
        ], [[
            'preparation_code' => '42699-01', 'route_code' => 'im', 'dose_1' => 2, 'frequency_1' => '26', 'days_1' => 4,
            'total_quantity' => 180, 'given_quantity' => 180, 'starts_on' => now()->toDateString(),
        ]]));
    }

    public function test_av_porcs_avec_deux_des_trois_donnees_part(): void
    {
        $this->sendAndExpectSent($this->declaration(IsabvForm::StockSupply, [
            'species_code' => '2.2.3', 'diagnosis_key' => '11.1', 'holding_number' => '1234565', 'holding_address' => 'Porcherie du Test, 1630 Bulle',
            'animals_count' => 30, 'av_treatment_days' => 5,
        ], [['preparation_code' => 'S00114', 'given_quantity' => 500]]));
    }

    public function test_fzl_animal_de_zoo_part(): void
    {
        $this->sendAndExpectSent($this->declaration(IsabvForm::ZooLab, [
            'species_code' => '5.2.2', 'holding_address' => 'Zoo du Test, 1700 Fribourg',
        ], [['preparation_code' => '52626-01', 'given_quantity' => 15]]));
    }

    public function test_le_nombre_de_consultations_part_pour_chaque_espece_et_autres(): void
    {
        foreach (['1.5' => 120, '1.4' => 80, '1.1' => 10, '1.11' => 5] as $species => $count) {
            $result = $this->client()->saveConsultation((int) now()->format('Y') - 1, $species, $count);
            $this->assertTrue($result->isOk, $species.' : '.(string) $result->message);
        }
    }

    public function test_la_liste_des_doublons_se_lit(): void
    {
        // Une réponse de forme inattendue lèverait IsabvUnavailable
        $this->client()->duplicates();
        $this->addToAssertionCount(1);
    }
}
