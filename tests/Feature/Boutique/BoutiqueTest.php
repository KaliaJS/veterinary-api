<?php

namespace Tests\Feature\Boutique;

use App\Enums\StatutFacture;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\CommandeBoutiqueLigne;
use App\Models\Facture;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Boutique : commandes en ligne (prix vitrine figé, remise = sortie de
 * stock), vente comptoir (une facture payée de lignes produit — pas
 * d'entité parallèle), vitrine (visibilité, promo, vedette) et chiffres
 * 7 jours dérivés des factures.
 */
class BoutiqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function produitEnRayon(int $quantite, array $attributs = []): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'est_boutique' => true,
            'prix' => 30,
            'promo_pct' => 0,
            ...$attributs,
        ]);

        Stock::factory()->create([
            'produit_id' => $produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => $quantite,
        ]);

        return $produit;
    }

    private function stockDe(Produit $produit): int
    {
        return Stock::withoutGlobalScopes()
            ->where('produit_id', $produit->id)
            ->where('clinique_id', $this->clinique->id)
            ->sole()
            ->quantite;
    }

    public function test_une_commande_en_ligne_fige_le_prix_vitrine_promotion_comprise(): void
    {
        $produit = $this->produitEnRayon(10, ['promo_pct' => 10]);

        $reponse = $this->postJson('/api/v1/boutique/commandes', [
            'client_nom' => 'Marie Aubert',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.numero', 'C-001')
            ->assertJsonPath('data.statut', 'prep')
            ->assertJsonPath('data.lignes.0.prix_chf', 27)
            ->assertJsonPath('data.total_ttc', 54);

        // La commande ne touche pas le stock — seule la remise le sort.
        $this->assertSame(10, $this->stockDe($produit));
    }

    public function test_un_article_hors_boutique_ne_se_commande_pas_en_ligne(): void
    {
        $horsVitrine = $this->produitEnRayon(5, ['est_boutique' => false]);

        $this->postJson('/api/v1/boutique/commandes', [
            'client_nom' => 'Refusé',
            'lignes' => [['produit_id' => $horsVitrine->id, 'quantite' => 1]],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes.0.produit_id');
    }

    public function test_le_cycle_de_commande_sort_le_stock_a_la_remise(): void
    {
        $produit = $this->produitEnRayon(10);
        $commande = CommandeBoutique::factory()->create(['clinique_id' => $this->clinique->id]);
        CommandeBoutiqueLigne::factory()->create([
            'commande_boutique_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 2,
        ]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/boutique/commandes/{$commande->id}/preparer", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'ready');

        $this->assertSame(10, $this->stockDe($produit));

        $this->postJson("/api/v1/boutique/commandes/{$commande->id}/retirer", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'done');

        $this->assertSame(8, $this->stockDe($produit));
        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => -2,
            'libelle' => 'Commande en ligne '.$commande->numero,
        ]);

        // Une commande retirée ne se remet pas deux fois.
        $this->postJson("/api/v1/boutique/commandes/{$commande->id}/retirer", [], $entetes)->assertUnprocessable();
    }

    public function test_la_vente_comptoir_est_une_facture_payee_qui_sort_le_stock(): void
    {
        $produit = $this->produitEnRayon(5, ['promo_pct' => 10]);

        $reponse = $this->postJson('/api/v1/boutique/vente', [
            'client_nom' => 'Client de passage',
            'moyen_paiement' => 'twint',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.statut', 'payee')
            ->assertJsonPath('data.moyen_paiement', 'twint')
            ->assertJsonPath('data.lignes.0.prix_chf', 27)
            ->assertJsonPath('data.total_ttc', 54);

        $this->assertSame(3, $this->stockDe($produit));

        $facture = Facture::withoutGlobalScopes()->find($reponse->json('data.id'));
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame($this->clinique->id, $facture->clinique_id);
        $this->assertStringStartsWith('FC-', $facture->numero);
    }

    public function test_le_panier_est_borne_au_stock_local(): void
    {
        $produit = $this->produitEnRayon(1);

        $this->postJson('/api/v1/boutique/vente', [
            'moyen_paiement' => 'cash',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 3]],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes.0.quantite');

        // Rien ne bouge : ni facture, ni stock.
        $this->assertSame(0, Facture::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->stockDe($produit));
    }

    public function test_la_vitrine_pilote_visibilite_promotion_et_vedette(): void
    {
        $produit = $this->produitEnRayon(5);

        $this->patchJson("/api/v1/boutique/produits/{$produit->id}/vitrine", [
            'est_boutique' => false,
            'promo_pct' => 15,
            'est_vedette' => true,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.est_boutique', false)
            ->assertJsonPath('data.promo_pct', 15)
            ->assertJsonPath('data.est_vedette', true);

        $this->patchJson("/api/v1/boutique/produits/{$produit->id}/vitrine", [
            'promo_pct' => 95,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('promo_pct');
    }

    public function test_les_chiffres_7_jours_derivent_des_factures_payees(): void
    {
        $produit = $this->produitEnRayon(10, ['prix' => 30, 'cout' => 12]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson('/api/v1/boutique/vente', [
            'moyen_paiement' => 'tpe',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ], $entetes)->assertCreated();

        // Une facture payée il y a dix jours reste hors période.
        $ancienne = Facture::factory()->payee()->create([
            'clinique_id' => $this->clinique->id,
            'payee_le' => now()->subDays(10),
        ]);
        $ancienne->lignes()->create([
            'produit_id' => $produit->id,
            'libelle' => $produit->nom,
            'quantite' => 5,
            'prix_chf' => 30,
            'type' => 'med',
        ]);

        $this->getJson('/api/v1/boutique/statistiques', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data.ventes')
            ->assertJsonPath('data.ventes.0.quantite', 2)
            ->assertJsonPath('data.ventes.0.revenu', 60)
            ->assertJsonPath('data.ventes.0.cout', 24)
            ->assertJsonPath('data.totaux.chiffre_affaires', 60)
            ->assertJsonPath('data.totaux.marge', 36);
    }
}
