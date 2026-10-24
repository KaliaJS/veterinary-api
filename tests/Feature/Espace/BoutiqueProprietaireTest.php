<?php

namespace Tests\Feature\Espace;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — boutiques : vitrine par clinique (stock local),
 * commande par clinique de retrait bornée par le stock, mes commandes.
 */
class BoutiqueProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_la_vitrine_montre_le_stock_de_chaque_clinique(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $organisation = $clinique->societe->organisation_id;
        $croquettes = Produit::factory()->create(['organisation_id' => $organisation, 'nom' => 'Croquettes rénales', 'prix' => 48, 'promo_pct' => 10, 'est_boutique' => true, 'est_sur_ordonnance' => false]);
        $ordonnance = Produit::factory()->create(['organisation_id' => $organisation, 'nom' => 'Meloxicam', 'est_boutique' => true, 'est_sur_ordonnance' => true]);
        Produit::factory()->create(['organisation_id' => $organisation, 'nom' => 'Hors vitrine', 'est_boutique' => false]);
        Stock::factory()->create(['produit_id' => $croquettes->id, 'clinique_id' => $clinique->id, 'quantite' => 3]);
        Stock::factory()->create(['produit_id' => $ordonnance->id, 'clinique_id' => $clinique->id, 'quantite' => 9]);

        $this->getJson('/api/v1/espace/boutique', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.0.nom', 'Deux Rives')
            ->assertJsonCount(2, 'data.0.produits')
            ->assertJsonPath('data.0.produits.0.nom', 'Croquettes rénales')
            ->assertJsonPath('data.0.produits.0.prix', 43.2)
            ->assertJsonPath('data.0.produits.0.promo_pct', 10)
            ->assertJsonPath('data.0.produits.0.stock', 3)
            ->assertJsonPath('data.0.produits.0.disponible', true)
            ->assertJsonPath('data.0.produits.1.nom', 'Meloxicam')
            ->assertJsonPath('data.0.produits.1.disponible', false);
    }

    public function test_le_panier_passe_une_commande_par_clinique_bornee_par_le_stock(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Anna Meyer']);
        $clinique = Clinique::factory()->create();
        $organisation = $clinique->societe->organisation_id;
        $fiche = Client::factory()->create(['user_id' => $user->id, 'organisation_id' => $organisation, 'prenom' => 'Anna', 'nom' => 'Meyer']);
        $croquettes = Produit::factory()->create(['organisation_id' => $organisation, 'prix' => 48, 'promo_pct' => 0, 'est_boutique' => true, 'est_sur_ordonnance' => false]);
        $vermifuge = Produit::factory()->create(['organisation_id' => $organisation, 'prix' => 12.5, 'promo_pct' => 0, 'est_boutique' => true, 'est_sur_ordonnance' => false]);
        Stock::factory()->create(['produit_id' => $croquettes->id, 'clinique_id' => $clinique->id, 'quantite' => 2]);
        Stock::factory()->create(['produit_id' => $vermifuge->id, 'clinique_id' => $clinique->id, 'quantite' => 5]);

        // Trop pour le stock local : refusé, rien n'est créé.
        $refus = $this->postJson('/api/v1/espace/commandes', ['commandes' => [
            ['clinique_id' => $clinique->id, 'lignes' => [['produit_id' => $croquettes->id, 'quantite' => 3]]],
        ]], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('commandes.0.lignes.0.quantite')
            ->assertJsonMissingPath('stock')
            ->json('errors')['commandes.0.lignes.0.quantite'][0];
        // Jamais le stock exact dans le refus (la vitrine ne le donne pas non plus)
        $this->assertSame('Pas assez de stock pour cette quantité de '.$croquettes->nom.' chez '.$clinique->nom.' : réduisez-la.', $refus);
        $this->assertStringNotContainsString('2', str_replace([$croquettes->nom, $clinique->nom], '', $refus));
        $this->assertSame(0, CommandeBoutique::withoutGlobalScopes()->count());

        $reponse = $this->postJson('/api/v1/espace/commandes', ['commandes' => [
            ['clinique_id' => $clinique->id, 'lignes' => [['produit_id' => $croquettes->id, 'quantite' => 2], ['produit_id' => $vermifuge->id, 'quantite' => 1]]],
        ]], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.statut', 'prep')
            ->assertJsonPath('data.0.statut_libelle', 'En préparation')
            ->assertJsonPath('data.0.clinique.id', $clinique->id)
            ->assertJsonPath('data.0.total_ttc', 108.5);

        $this->assertStringStartsWith('C-', $reponse->json('data.0.numero'));
        $this->assertDatabaseHas('commandes_boutique', ['clinique_id' => $clinique->id, 'client_id' => $fiche->id, 'client_nom' => 'Anna Meyer', 'statut' => 'prep']);
        // Le stock n'est pas réservé : la sortie se fait à la remise.
        $this->assertSame(2, Stock::withoutGlobalScopes()->where('produit_id', $croquettes->id)->value('quantite'));

        $this->getJson('/api/v1/espace/commandes', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero', $reponse->json('data.0.numero'))
            ->assertJsonPath('data.0.lignes.0.quantite', 2);
    }

    /** Le panier chiffré par le serveur : total, TVA au taux de chaque société (rien hors TVA), hors taxes (24.10.2026). */
    public function test_le_panier_est_chiffre_par_le_serveur(): void
    {
        $assujettie = Clinique::factory()->create();
        $horsTva = Clinique::factory()->create();
        $horsTva->societe->update(['assujettie_tva' => false]);
        foreach ([$assujettie, $horsTva] as $clinique) {
            $clinique->update(['services' => array_values(array_unique([...($clinique->services ?? []), 'boutique']))]);
        }
        $croquettes = Produit::factory()->create(['organisation_id' => $assujettie->societe->organisation_id, 'prix' => 50, 'promo_pct' => 10, 'est_boutique' => true]);
        $collier = Produit::factory()->create(['organisation_id' => $horsTva->societe->organisation_id, 'prix' => 15, 'promo_pct' => 0, 'est_boutique' => true]);
        $panier = ['commandes' => [
            ['clinique_id' => $assujettie->id, 'lignes' => [['produit_id' => $croquettes->id, 'quantite' => 2]]],
            ['clinique_id' => $horsTva->id, 'lignes' => [['produit_id' => $collier->id, 'quantite' => 1]]],
        ]];

        // Hors connexion aussi : la vitrine est publique
        $this->postJson('/api/v1/public/boutique/apercu', $panier)
            ->assertOk()
            // 2 × 45.00 = 90.00 TTC, dont 6.74 de TVA à 8.1 % ; chaque ligne a son total
            ->assertJsonPath('data.cliniques.0.lignes.0.produit_id', $croquettes->id)
            ->assertJsonPath('data.cliniques.0.lignes.0.total', 90)
            ->assertJsonPath('data.cliniques.1.lignes.0.total', 15)
            ->assertJsonPath('data.cliniques.0.total_ttc', 90)
            ->assertJsonPath('data.cliniques.0.montant_tva', 6.74)
            ->assertJsonPath('data.cliniques.0.total_ht', 83.26)
            ->assertJsonPath('data.cliniques.0.taux_tva', 8.1)
            ->assertJsonPath('data.cliniques.1.montant_tva', 0)
            ->assertJsonPath('data.cliniques.1.taux_tva', 0)
            ->assertJsonPath('data.total_ttc', 105)
            ->assertJsonPath('data.montant_tva', 6.74)
            ->assertJsonPath('data.taux_tva', null);

        // Un article d'une autre organisation ne compte pas dans le panier de cette clinique
        $this->postJson('/api/v1/public/boutique/apercu', ['commandes' => [
            ['clinique_id' => $horsTva->id, 'lignes' => [['produit_id' => $croquettes->id, 'quantite' => 1]]],
        ]])->assertJsonPath('data.total_ttc', 0);
    }

    public function test_un_article_hors_vitrine_ou_sur_ordonnance_ne_se_commande_pas(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->create();
        $organisation = $clinique->societe->organisation_id;
        $prive = Produit::factory()->create(['organisation_id' => $organisation, 'est_boutique' => false]);
        $medicament = Produit::factory()->create(['organisation_id' => $organisation, 'est_boutique' => true, 'est_sur_ordonnance' => true]);
        Stock::factory()->create(['produit_id' => $medicament->id, 'clinique_id' => $clinique->id, 'quantite' => 5]);

        $this->postJson('/api/v1/espace/commandes', ['commandes' => [['clinique_id' => $clinique->id, 'lignes' => [['produit_id' => $prive->id, 'quantite' => 1]]]]], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commandes.0.lignes.0.quantite' => 'Seul un article visible en boutique se commande en ligne.']);
        $this->postJson('/api/v1/espace/commandes', ['commandes' => [['clinique_id' => $clinique->id, 'lignes' => [['produit_id' => $medicament->id, 'quantite' => 1]]]]], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commandes.0.lignes.0.quantite' => $medicament->nom.' : en clinique uniquement, sur ordonnance.']);
        $this->assertSame(0, CommandeBoutique::withoutGlobalScopes()->count());
    }
}
