<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Client;
use App\Models\Clinique;
use App\Services\Isabv\DeclarationRules;
use App\Services\Isabv\DeclarationXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV, le n° BDTA (09.11.2026) : 7 chiffres dont le dernier est un
 * chiffre de contrôle (directives techniques, ch. 12 : « Vérification des
 * 7 chiffres », erreur « le chiffre de contrôle n'est pas correct »). Le
 * calcul est celui que le serveur de test de l'OSAV applique, relevé le
 * 09.11.2026 : modulo 10, poids 3 et 1 depuis la droite (1234565 et
 * 1000009 acceptés, toutes les autres fins refusées).
 */
class NumeroBdtaTest extends TestCase
{
    use EmetDesJetonsApi;
    use PrepareLeSiAbv;
    use RefreshDatabase;

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->donneesDeBaseSiAbv();
        $this->clinique = Clinique::factory()->create();
        $this->cliniqueQuiDeclare($this->clinique);
    }

    private function admin(): array
    {
        return $this->enTetes($this->administrateurAvecJeton($this->clinique)['jeton']);
    }

    private function declarationEn(string $holdingNumber): AntibioticDeclaration
    {
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::LivestockTreatment, 'status' => DeclarationStatus::ToComplete,
            'visit_on' => '2026-11-09', 'species_code' => '2.1.5', 'diagnosis_key' => '2.1', 'holding_number' => $holdingNumber, 'holding_address' => 'Ferme du Pont, 1700 Fribourg', 'animals_count' => 1,
        ]);
        $declaration->lines()->create(['preparation_code' => '44444-02', 'route_code' => 'mm', 'dose_1' => 1, 'frequency_1' => '6', 'days_1' => 2, 'total_quantity' => 2, 'given_quantity' => 2]);

        return $declaration;
    }

    public function test_la_fiche_du_detenteur_refuse_un_numero_bdta_au_chiffre_de_controle_faux(): void
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $this->patchJson('/api/v1/clients/'.$client->id, ['holding_number' => '1234567'], $this->admin())
            ->assertUnprocessable()->assertJsonValidationErrors('holding_number');
        $this->patchJson('/api/v1/clients/'.$client->id, ['holding_number' => '123456'], $this->admin())
            ->assertUnprocessable()->assertJsonValidationErrors('holding_number');
        $this->patchJson('/api/v1/clients/'.$client->id, ['holding_number' => '1234565'], $this->admin())->assertOk();
        $this->patchJson('/api/v1/clients/'.$client->id, ['holding_number' => '123 456 5'], $this->admin())->assertOk();
        $this->patchJson('/api/v1/clients/'.$client->id, ['holding_number' => '0001236'], $this->admin())->assertOk();
    }

    public function test_la_creation_d_un_detenteur_refuse_un_numero_bdta_au_chiffre_de_controle_faux(): void
    {
        $before = Client::query()->withoutGlobalScopes()->count();

        $this->postJson('/api/v1/clients', ['prenom' => 'Lena', 'nom' => 'Rochat', 'holding_number' => '1234567'], $this->admin())
            ->assertUnprocessable()->assertJsonValidationErrors('holding_number');
        $this->assertSame($before, Client::query()->withoutGlobalScopes()->count());

        $this->postJson('/api/v1/clients', ['prenom' => 'Lena', 'nom' => 'Rochat', 'holding_number' => '1234565'], $this->admin())->assertCreated();
    }

    public function test_un_numero_bdta_saisi_avec_des_tirets_est_accepte(): void
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $this->patchJson('/api/v1/clients/'.$client->id, ['holding_number' => '123-456-5'], $this->admin())->assertOk();
        $this->patchJson('/api/v1/clients/'.$client->id, ['holding_number' => '123-456-7'], $this->admin())->assertUnprocessable();
    }

    public function test_une_declaration_prete_au_numero_bdta_faux_ne_part_pas_et_redevient_a_completer(): void
    {
        Http::fake();
        $declaration = $this->declarationEn('1234567');
        $declaration->forceFill(['status' => DeclarationStatus::Ready])->save();

        (new SendAntibioticDeclaration($declaration->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(DeclarationStatus::ToComplete, $declaration->fresh()->status);
    }

    public function test_une_declaration_au_numero_bdta_faux_reste_a_completer(): void
    {
        $this->assertContains('un n° BDTA juste : 7 chiffres, le dernier est un chiffre de contrôle', app(DeclarationRules::class)->missing($this->declarationEn('1234567')));
        $this->assertNotContains('un n° BDTA juste : 7 chiffres, le dernier est un chiffre de contrôle', app(DeclarationRules::class)->missing($this->declarationEn('1234565')));
    }

    public function test_le_numero_bdta_part_en_chiffres_seuls(): void
    {
        $declaration = $this->declarationEn('123 456 5');

        $xml = (new DeclarationXml)->build($declaration->fresh(), $this->clinique, null);

        $this->assertStringContainsString('<tvd>1234565</tvd>', $xml);
    }
}
