<?php

namespace Tests\Feature\Stocks;

use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Lot;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\GestionnaireStock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Tranches de scope du domaine (docs/data-model.md §14) : le catalogue
 * est d'ORGANISATION — invisible d'une organisation étrangère ; les
 * quantités et le journal se LISENT toutes cliniques de l'organisation
 * confondues ; les écritures visent la clinique du jeton ou une clinique
 * SŒUR explicite, jamais une autre organisation. Les lots et les commandes
 * fournisseur restent à la clinique du jeton (on commande depuis la
 * clinique, décision de Jim du 28.10.2026).
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

    /** On commande depuis la clinique (décision de Jim, 28.10.2026) : celles d'une clinique sœur ne se voient ni ne se touchent d'ici. */
    public function test_les_commandes_d_une_clinique_soeur_sont_invisibles_et_intouchables(): void
    {
        $commandeSoeur = CommandeFournisseur::factory()->create(['clinique_id' => $this->cliniqueSoeur->id]);
        $commandeEtrangere = CommandeFournisseur::factory()->create(['clinique_id' => $this->cliniqueEtrangere->id]);
        $commandeIci = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);
        $ligneSoeur = CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commandeSoeur->id, 'quantite' => 4]);

        $this->getJson('/api/v1/stocks/commandes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $commandeIci->id);

        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        foreach ([$commandeSoeur, $commandeEtrangere] as $ailleurs) {
            foreach (['envoyer', 'receptionner', 'lignes', 'vetpoint'] as $geste) {
                $this->postJson("/api/v1/stocks/commandes/{$ailleurs->id}/{$geste}", ['produit_id' => $produit->id, 'quantite' => 1, 'adresse_id' => 'A1', 'livraison' => 'standard'], $this->enTetes($this->jeton))
                    ->assertNotFound();
            }
            $this->deleteJson("/api/v1/stocks/commandes/{$ailleurs->id}", [], $this->enTetes($this->jeton))->assertNotFound();
        }
        // Ni ses lignes modifiées ou retirées
        $this->patchJson("/api/v1/stocks/commandes/{$commandeSoeur->id}/lignes/{$ligneSoeur->id}", ['quantite' => 9], $this->enTetes($this->jeton))->assertNotFound();
        $this->deleteJson("/api/v1/stocks/commandes/{$commandeSoeur->id}/lignes/{$ligneSoeur->id}", [], $this->enTetes($this->jeton))->assertNotFound();
        $this->assertNotSame(9, (int) $ligneSoeur->refresh()->quantite);
        $this->assertSame('draft', $commandeSoeur->refresh()->statut->value);
    }

    public function test_l_ajustement_et_le_stock_initial_atteignent_une_clinique_soeur_jamais_une_etrangere(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", [
            'quantite' => 4,
            'motif' => 'recep',
            'clinique_id' => $this->cliniqueSoeur->id,
        ], $this->enTetes($this->jeton))->assertOk();

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", [
            'quantite' => 4,
            'motif' => 'recep',
            'clinique_id' => $this->cliniqueEtrangere->id,
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('clinique_id');

        $quantites = Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->pluck('quantite', 'clinique_id');
        $this->assertSame(4, $quantites[$this->cliniqueSoeur->id]);
        $this->assertArrayNotHasKey($this->clinique->id, $quantites);
        $this->assertArrayNotHasKey($this->cliniqueEtrangere->id, $quantites);
        $this->assertSame([$this->cliniqueSoeur->id], MouvementStock::withoutGlobalScopes()->pluck('clinique_id')->all());

        $this->postJson('/api/v1/stocks/produits', [
            'nom' => 'Article livré chez la sœur',
            'categorie' => 'mat',
            'prix' => 10,
            'quantite_initiale' => 7,
            'clinique_id' => $this->cliniqueEtrangere->id,
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('clinique_id');

        $id = $this->postJson('/api/v1/stocks/produits', [
            'nom' => 'Article livré chez la sœur',
            'categorie' => 'mat',
            'prix' => 10,
            'quantite_initiale' => 7,
            'clinique_id' => $this->cliniqueSoeur->id,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->assertSame(
            [$this->cliniqueSoeur->id => 7],
            Stock::withoutGlobalScopes()->where('produit_id', $id)->pluck('quantite', 'clinique_id')->all(),
        );
    }

    public function test_le_gestionnaire_refuse_une_ecriture_hors_de_l_organisation_meme_sans_passer_par_http(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($this->jeton))->assertOk();

        $this->expectException(AuthorizationException::class);

        app(GestionnaireStock::class)->entrer($produit, 1, 'Intrusion', cliniqueId: $this->cliniqueEtrangere->id);
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
