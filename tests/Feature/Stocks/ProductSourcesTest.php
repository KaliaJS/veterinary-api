<?php

namespace Tests\Feature\Stocks;

use App\Models\Clinique;
use App\Models\Produit;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Case 3 : chaque article a ses sources, rangées par préférence, chacune avec
 * sa référence et son prix d'achat chez ce fournisseur. Les fournisseurs
 * étant ceux de la clinique, les sources aussi : une clinique sœur a les siennes.
 */
class ProductSourcesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function fournisseur(string $nom, ?Clinique $clinique = null): Supplier
    {
        return Supplier::query()->withoutGlobalScopes()->create(['clinique_id' => ($clinique ?? $this->clinique)->id, 'name' => $nom, 'channel' => 'manual']);
    }

    public function test_un_article_a_ses_sources_par_preference_avec_reference_et_prix(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'seuil_alerte' => 3]);
        $vetpoint = $this->fournisseur('Vetpoint');
        $covetrus = $this->fournisseur('Covetrus');

        $this->putJson('/api/v1/stocks/produits/'.$produit->id.'/sources', [
            'is_auto_order' => true,
            'reorder_up_to' => 6,
            'sources' => [
                ['supplier_id' => $covetrus->id, 'reference' => 'CV-778812', 'cost' => 25.1],
                ['supplier_id' => $vetpoint->id, 'reference' => 'Swissmedic 54321-01', 'cost' => 24.6],
            ],
        ], $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.is_auto_order', true)
            ->assertJsonPath('data.reorder_up_to', 6)
            ->assertJsonPath('data.sources.0.supplier_name', 'Covetrus')
            ->assertJsonPath('data.sources.0.rank', 1)
            ->assertJsonPath('data.sources.0.reference', 'CV-778812')
            ->assertJsonPath('data.sources.0.cost', 25.1)
            ->assertJsonPath('data.sources.1.supplier_name', 'Vetpoint')
            ->assertJsonPath('data.sources.1.rank', 2);

        // Rangées autrement : la préférence suit l'ordre envoyé, sans doublon
        $this->putJson('/api/v1/stocks/produits/'.$produit->id.'/sources', [
            'sources' => [
                ['supplier_id' => $vetpoint->id, 'reference' => 'Swissmedic 54321-01', 'cost' => 24.6],
                ['supplier_id' => $covetrus->id, 'reference' => 'CV-778812', 'cost' => 25.1],
            ],
        ], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.sources.0.supplier_name', 'Vetpoint');
        $this->assertSame(2, $produit->sources()->count());

        // Lues avec l'article
        $this->getJson('/api/v1/stocks/produits/'.$produit->id, $this->enTetes($this->jeton))
            ->assertJsonPath('data.sources.0.supplier_name', 'Vetpoint')->assertJsonPath('data.sources.1.cost', 25.1);
    }

    public function test_les_sources_sont_celles_de_la_clinique(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $ici = $this->fournisseur('Vetpoint');
        $laBas = $this->fournisseur('Covetrus', $this->soeur);

        // Un fournisseur d'une autre clinique ne se choisit pas d'ici
        $this->putJson('/api/v1/stocks/produits/'.$produit->id.'/sources', [
            'sources' => [['supplier_id' => $laBas->id]],
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('sources.0.supplier_id');

        // La source de la clinique sœur reste, mais ne se voit pas d'ici
        $produit->sources()->create(['supplier_id' => $laBas->id, 'rank' => 1]);
        $this->putJson('/api/v1/stocks/produits/'.$produit->id.'/sources', [
            'sources' => [['supplier_id' => $ici->id, 'cost' => 3]],
        ], $this->enTetes($this->jeton))->assertOk()->assertJsonCount(1, 'data.sources');
        $this->assertDatabaseHas('product_sources', ['produit_id' => $produit->id, 'supplier_id' => $laBas->id]);

        // Le même fournisseur deux fois : refusé
        $this->putJson('/api/v1/stocks/produits/'.$produit->id.'/sources', [
            'sources' => [['supplier_id' => $ici->id], ['supplier_id' => $ici->id]],
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('sources.1.supplier_id');
    }
}
