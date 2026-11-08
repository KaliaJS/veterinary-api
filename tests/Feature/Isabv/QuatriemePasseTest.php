<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Models\AntibioticDeclaration;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\IsabvConnection;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\Sejour;
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
 * SI ABV, suites de la quatrième vérification (08.11.2026) : le
 * renouvellement d'une ordonnance signée avant se déclare ; une déclaration
 * d'hospitalisation va à qui prescrit ; un traitement arrêté sans prise
 * n'est pas déclaré ; deux sites ne partagent pas l'IDE sans complément ;
 * retirer une ligne d'antibiotique demande de prescrire ; un lien à la main
 * ne rend pas un antibiotique non déclaré ; une saisie sur le site de
 * l'OSAV annulée le dit.
 */
class QuatriemePasseTest extends TestCase
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

    private function antibiotique(): Produit
    {
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01', 'est_sur_ordonnance' => false,
            'est_stupefiant' => false, 'dose_unit' => 'cp', 'content_per_unit' => 20]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 30]);

        return $produit;
    }

    public function test_le_renouvellement_d_une_ordonnance_signee_avant_se_declare(): void
    {
        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_OSAV_WEB]);
        $produit = $this->antibiotique();
        $ordonnance = $this->postJson('/api/v1/ordonnances', ['patient_id' => $this->patient->id, 'renouvellements' => 1, 'lignes' => [['produit_id' => $produit->id, 'posologie' => '1 cp']]],
            $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame(0, AntibioticDeclaration::withoutGlobalScopes()->count());

        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_VETERINARY]);
        $this->postJson('/api/v1/ordonnances/'.$ordonnance['id'].'/renouveler', [], $this->enTetes($this->jeton))->assertCreated()->assertJsonPath('data.antibiotic_declarations.0.status', 'to_complete');
    }

    public function test_une_declaration_d_hospitalisation_va_a_qui_prescrit_et_un_traitement_sans_prise_ne_se_declare_pas(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Europe/Zurich'));
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id,
            'box' => 2, 'statut' => 'surv', 'perfusion' => null, 'admis_le' => now()->subDay(), 'veterinaire_id' => null]);
        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');

        $traitement = $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/traitements", ['label' => 'Amoxicilline', 'product_id' => $this->antibiotique()->id, 'dose_amount' => 1, 'times' => [480]],
            $this->enTetes($asv))->assertCreated()->json('data');
        // Ni vétérinaire du séjour, ni droit de prescrire : à personne, donc à chacun qui prescrit
        $this->assertNull(AntibioticDeclaration::withoutGlobalScopes()->sole()->employe_id);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/traitements/{$traitement['id']}/arreter", [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(0, AntibioticDeclaration::withoutGlobalScopes()->count());
    }

    public function test_deux_sites_ne_partagent_pas_l_ide_sans_complement(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        Http::fake(['https://isabv.test/api/isabv/jwt/create' => Http::response('a.b.c')]);
        $admin = $this->enTetes($this->administrateurAvecJeton($soeur)['jeton']);

        $this->putJson('/api/v1/isabv/connection', ['variant' => 1, 'token' => 'feb3786f-c5d2-47cc'], $admin)->assertUnprocessable()->assertJsonValidationErrors('appendix');
        $this->putJson('/api/v1/isabv/connection', ['variant' => 1, 'appendix' => 'Site de Bulle', 'token' => 'feb3786f-c5d2-47cc'], $admin)->assertOk();
    }

    public function test_retirer_une_ligne_d_antibiotique_demande_de_prescrire(): void
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id]);
        $produit = $this->antibiotique();
        $ligne = FactureLigne::factory()->create(['facture_id' => $facture->id, 'produit_id' => $produit->id, 'libelle' => $produit->nom, 'type' => 'med']);
        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');

        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", [], $this->enTetes($asv))->assertForbidden();
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", [], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_un_lien_a_la_main_ne_rend_pas_un_antibiotique_non_declare_et_la_vitrine_lit_le_code_barres_sans_espaces(): void
    {
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $admin = $this->enTetes($this->administrateurAvecJeton($this->clinique)['jeton']);
        $this->patchJson('/api/v1/stocks/produits/'.$produit->id.'/isabv', ['preparation_code' => '33333-01'], $admin)->assertUnprocessable();

        $catalogue = ProduitVeterinaire::query()->create(['numero_autorisation' => '12345', 'nom' => 'Amoxicilline', 'nom_court' => 'Amoxicilline', 'categorie' => 'medicament']);
        ProduitEmballage::query()->create(['produit_id' => $catalogue->id, 'numero_emballage' => '12345001', 'sequence' => '1', 'gtin' => '7680123450016']);
        $espaces = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_boutique' => true, 'est_sur_ordonnance' => false, 'est_stupefiant' => false, 'ean' => '7680 1234 5001 6']);
        $this->assertNotContains($espaces->id, app(PublicShop::class)->articlesQuery($this->clinique->societe->organisation)->pluck('id')->all());
    }

    public function test_une_saisie_sur_le_site_de_l_osav_annulee_le_dit(): void
    {
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::LivestockTreatment,
            'status' => DeclarationStatus::EnteredOnWeb, 'source_type' => $ordonnance->getMorphClass(), 'source_id' => $ordonnance->id, 'visit_on' => aujourdhui()]);

        app(Declarations::class)->cancelFor($ordonnance);

        $this->assertSame(DeclarationStatus::EnteredOnWeb, $declaration->fresh()->status);
        $this->assertStringContainsString('désactivez aussi cette déclaration dans l’application web de l’OSAV', (string) $declaration->fresh()->osav_message);
    }
}
