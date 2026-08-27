<?php

namespace Tests\Feature\Stocks;

use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\Lot;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Tranches de scope du domaine (docs/data-model.md §14) : le catalogue
 * est d'ORGANISATION — invisible d'une organisation étrangère ; les
 * quantités et le journal se LISENT toutes cliniques de l'organisation
 * confondues, mais les écritures (ajustements, commandes, lots) sont
 * bornées à la CLINIQUE du jeton.
 */
class StocksIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueEtrangere;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_le_catalogue_d_une_autre_organisation_est_invisible(): void
    {
        $etranger = Produit::factory()->create([
            'organisation_id' => $this->cliniqueEtrangere->societe->organisation_id,
        ]);
        Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $this->getJson('/api/v1/stocks/produits', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/stocks/produits/{$etranger->id}", $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_l_ajustement_n_ecrit_que_le_stock_de_la_clinique_du_jeton(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 5]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->cliniqueSoeur->id, 'quantite' => 5]);

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", [
            'quantite' => -3,
            'motif' => 'usage',
        ], $this->enTetes($this->jeton))->assertOk();

        $quantites = Stock::withoutGlobalScopes()
            ->where('produit_id', $produit->id)
            ->pluck('quantite', 'clinique_id');

        $this->assertSame(2, $quantites[$this->clinique->id]);
        $this->assertSame(5, $quantites[$this->cliniqueSoeur->id]);
        $this->assertSame(
            [$this->clinique->id],
            MouvementStock::withoutGlobalScopes()->pluck('clinique_id')->unique()->values()->all(),
        );
    }

    public function test_les_commandes_d_une_clinique_soeur_sont_invisibles_et_intouchables(): void
    {
        $commandeSoeur = CommandeFournisseur::factory()->create(['clinique_id' => $this->cliniqueSoeur->id]);

        $this->getJson('/api/v1/stocks/commandes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/stocks/commandes/{$commandeSoeur->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertNotFound();

        $this->deleteJson("/api/v1/stocks/commandes/{$commandeSoeur->id}", [], $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_les_lots_d_une_clinique_soeur_sont_invisibles_et_intouchables(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $lotSoeur = Lot::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->cliniqueSoeur->id]);

        $this->getJson('/api/v1/stocks/lots', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/stocks/lots/{$lotSoeur->id}/ecarter", [], $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_une_commande_ne_reference_pas_un_produit_d_une_autre_organisation(): void
    {
        $etranger = Produit::factory()->create([
            'organisation_id' => $this->cliniqueEtrangere->societe->organisation_id,
        ]);

        $this->postJson('/api/v1/stocks/commandes', [
            'fournisseur' => 'Vetopharm SA',
            'lignes' => [['produit_id' => $etranger->id, 'quantite' => 5]],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes.0.produit_id');
    }

    public function test_un_article_cree_est_rattache_a_l_organisation_du_jeton(): void
    {
        $reponse = $this->postJson('/api/v1/stocks/produits', [
            'nom' => 'Article du jeton',
            'categorie' => 'mat',
            'prix' => 5,
        ], $this->enTetes($this->jeton))->assertCreated();

        $produit = Produit::withoutGlobalScopes()->find($reponse->json('data.id'));

        $this->assertSame($this->clinique->societe->organisation_id, $produit->organisation_id);
    }
}
