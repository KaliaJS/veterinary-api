<?php

namespace Tests\Feature\Stocks;

use App\Enums\StatutOrdonnance;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les principes actifs d'un article du stock (dossier patient, 03.11.2026) :
 * repris du catalogue Swissmedic par son code-barres (GTIN), tels quels
 * (souvent en latin), pour relier une molécule notée à la marque prescrite.
 * Sans code-barres connu du catalogue, aucun.
 */
class ProductSubstancesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $metacam = ProduitVeterinaire::query()->create(['numero_autorisation' => '52802', 'nom' => 'Metacam 1.5 mg/ml ad us. vet.', 'nom_court' => 'Metacam', 'categorie' => 'medicament', 'atc_code' => 'QM01AC06', 'substances' => ['meloxicamum']]);
        ProduitEmballage::query()->create(['produit_id' => $metacam->id, 'numero_emballage' => '52802001', 'gtin' => '7680528020014']);
    }

    private function article(array $attributs = []): Produit
    {
        return Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, ...$attributs]);
    }

    public function test_l_article_sert_les_substances_du_catalogue(): void
    {
        $metacam = $this->article(['nom' => 'Metacam 1,5 mg/ml', 'ean' => '7680528020014']);
        $sansCode = $this->article(['nom' => 'Mélange maison', 'ean' => null]);

        $this->getJson("/api/v1/stocks/produits/{$metacam->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.substances', ['meloxicamum']);
        $this->getJson("/api/v1/stocks/produits/{$sansCode->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.substances', []);
        // La liste que lit le formulaire d'ordonnance
        $liste = collect($this->getJson('/api/v1/stocks/produits?per_page=100', $this->enTetes($this->jeton))->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['meloxicamum'], $liste[$metacam->id]['substances']);
        $this->assertSame([], $liste[$sansCode->id]['substances']);
    }

    public function test_l_ordonnance_et_le_fil_servent_les_substances(): void
    {
        $metacam = $this->article(['nom' => 'Metacam 1,5 mg/ml', 'ean' => '7680528020014']);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $chien = Patient::factory()->for($client)->create(['organisation_id' => $client->organisation_id]);
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'organisation_id' => $client->organisation_id, 'patient_id' => $chien->id, 'signee_le' => now()->subDays(2)->toDateString(), 'statut' => StatutOrdonnance::Active]);
        OrdonnanceLigne::factory()->create(['ordonnance_id' => $ordonnance->id, 'produit_id' => $metacam->id, 'duree' => '7 jours']);

        $this->getJson("/api/v1/ordonnances/{$ordonnance->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.lignes.0.substances', ['meloxicamum']);
        $ligne = collect($this->getJson("/api/v1/patients/{$chien->id}/fil?type=prescription", $this->enTetes($this->jeton))->assertOk()->json('data'))->firstWhere('ordonnance_id', $ordonnance->id);
        $this->assertSame(['meloxicamum'], $ligne['lignes'][0]['substances']);
    }
}
