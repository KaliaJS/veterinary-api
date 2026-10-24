<?php

namespace Tests\Feature\Stocks;

use App\Enums\MotifAjustementStock;
use App\Models\Clinique;
use App\Models\Lot;
use App\Models\MouvementStock;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Catalogue d'organisation, quantités par clinique : la lecture expose
 * la ventilation de toutes les cliniques, chaque geste laisse une ligne
 * au journal (jamais de décrément silencieux) et les sorties sont
 * bornées à zéro — l'écart tracé est l'écart réel.
 */
class StockTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function produitAvecStock(int $quantite, array $attributs = []): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            ...$attributs,
        ]);

        Stock::factory()->create([
            'produit_id' => $produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => $quantite,
        ]);

        return $produit;
    }

    public function test_creer_un_article_pose_le_stock_initial_et_le_journalise(): void
    {
        $reponse = $this->postJson('/api/v1/stocks/produits', [
            'nom' => 'Gel articulaire chien · 250 ml',
            'categorie' => 'soin',
            'prix' => 21.5,
            'cout' => 9.9,
            'seuil_alerte' => 4,
            'quantite_initiale' => 12,
            'peremption' => '10.2027',
            'fournisseur' => 'DermaPet SA',
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.reference', 'ART-0001')
            ->assertJsonPath('data.peremption', '10.2027')
            ->assertJsonPath('data.stock_total', 12);

        $produit = Produit::withoutGlobalScopes()->find($reponse->json('data.id'));

        $this->assertSame($this->clinique->societe->organisation_id, $produit->organisation_id);
        $this->assertDatabaseHas('stocks', [
            'produit_id' => $produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => 12,
        ]);
        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => 12,
            'libelle' => 'Stock initial',
            'motif' => MotifAjustementStock::Reception->value,
        ]);
    }

    public function test_l_ajustement_motive_applique_l_ecart_et_le_journalise(): void
    {
        $produit = $this->produitAvecStock(5);

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", [
            'quantite' => -2,
            'motif' => 'casse',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.stock_total', 3);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => -2,
            'libelle' => 'Casse / perte',
            'motif' => MotifAjustementStock::Casse->value,
        ]);
    }

    public function test_une_sortie_est_bornee_au_stock_et_trace_l_ecart_reel(): void
    {
        $produit = $this->produitAvecStock(1);

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", [
            'quantite' => -5,
            'motif' => 'peremp',
        ], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(0, Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->sole()->quantite);
        $this->assertSame(-1, MouvementStock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole()->quantite);
    }

    public function test_une_sortie_sur_un_stock_a_zero_est_refusee_sans_trace(): void
    {
        $produit = $this->produitAvecStock(0);

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", [
            'quantite' => -3,
            'motif' => 'usage',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantite');

        $this->assertSame(0, MouvementStock::withoutGlobalScopes()->count());
    }

    public function test_un_ajustement_sans_motif_est_refuse(): void
    {
        $produit = $this->produitAvecStock(5);

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", [
            'quantite' => 2,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motif');
    }

    public function test_la_fiche_expose_la_ventilation_de_toutes_les_cliniques_de_l_organisation(): void
    {
        $produit = $this->produitAvecStock(3);
        Stock::factory()->create([
            'produit_id' => $produit->id,
            'clinique_id' => $this->cliniqueSoeur->id,
            'quantite' => 7,
        ]);

        $this->getJson("/api/v1/stocks/produits/{$produit->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.stock_total', 10)
            ->assertJsonCount(2, 'data.stocks');
    }

    public function test_le_journal_d_un_produit_montre_les_gestes_de_toutes_les_cliniques(): void
    {
        $produit = $this->produitAvecStock(5);
        MouvementStock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id]);
        MouvementStock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->cliniqueSoeur->id]);

        $this->getJson("/api/v1/stocks/produits/{$produit->id}/mouvements", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_l_index_filtre_par_categorie_et_par_recherche(): void
    {
        $this->produitAvecStock(5, ['nom' => 'Meloxicam 1,5 mg/ml · 100 ml', 'categorie' => 'med', 'reference' => 'MED-0114']);
        $this->produitAvecStock(5, ['nom' => 'Collerette T45', 'categorie' => 'mat', 'reference' => 'MAT-0610']);

        $entetes = $this->enTetes($this->jeton);

        $this->getJson('/api/v1/stocks/produits?categorie=med', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nom', 'Meloxicam 1,5 mg/ml · 100 ml');

        $this->getJson('/api/v1/stocks/produits?q=collerette', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'MAT-0610');
    }

    public function test_ecarter_un_lot_sort_le_stock_avec_un_mouvement_motive_et_supprime_le_lot(): void
    {
        $produit = $this->produitAvecStock(6);
        $lot = Lot::factory()->perime()->create([
            'produit_id' => $produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => 4,
        ]);

        $this->postJson("/api/v1/stocks/lots/{$lot->id}/ecarter", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('lots', ['id' => $lot->id]);
        $this->assertSame(2, Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->sole()->quantite);
        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => -4,
            'motif' => MotifAjustementStock::Peremption->value,
        ]);
    }

    /** Un lot de stupéfiant périmé se détruit par le registre : destruction inscrite, sans patient, avec le droit (24.10.2026). */
    public function test_ecarter_un_lot_de_stupefiant_inscrit_la_destruction_au_registre(): void
    {
        $produit = $this->produitAvecStock(6, ['est_stupefiant' => true]);
        MouvementStupefiant::factory()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $produit->id, 'quantite' => 6]);
        $lot = Lot::factory()->perime()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 4]);

        ['jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->postJson("/api/v1/stocks/lots/{$lot->id}/ecarter", [], $this->enTetes($jetonAsv))->assertForbidden();
        $this->assertDatabaseHas('lots', ['id' => $lot->id]);

        $this->postJson("/api/v1/stocks/lots/{$lot->id}/ecarter", [], $this->enTetes($this->jeton))->assertNoContent();

        $destruction = MouvementStupefiant::withoutGlobalScopes()->where('quantite', '<', 0)->sole();
        $this->assertSame('-4.00', $destruction->quantite);
        $this->assertSame('Destruction : lot '.$lot->numero_lot.' périmé', $destruction->motif);
        $this->assertNull($destruction->patient_id);
        $this->assertSame(2, Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->sole()->quantite);
        $this->assertDatabaseMissing('lots', ['id' => $lot->id]);
    }

    public function test_les_lots_sont_listes_du_plus_urgent_au_plus_lointain(): void
    {
        $produit = $this->produitAvecStock(10);
        $lointain = Lot::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'peremption_le' => now()->addYear()->startOfMonth()]);
        $urgent = Lot::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'peremption_le' => now()->addMonth()->startOfMonth()]);

        $this->getJson('/api/v1/stocks/lots', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.id', $urgent->id)
            ->assertJsonPath('data.1.id', $lointain->id);
    }
}
