<?php

namespace Tests\Feature\Boutique;

use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Tranches de scope : les commandes boutique sont celles de la clinique
 * de RETRAIT (celle du jeton) ; la vente comptoir ne puise que dans le
 * stock local ; la vitrine, elle, est un champ du catalogue
 * d'ORGANISATION.
 */
class BoutiqueIsolationTest extends TestCase
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

    public function test_les_commandes_d_une_clinique_soeur_sont_invisibles_et_intouchables(): void
    {
        $commandeSoeur = CommandeBoutique::factory()->prete()->create(['clinique_id' => $this->cliniqueSoeur->id]);

        $this->getJson('/api/v1/boutique/commandes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/boutique/commandes/{$commandeSoeur->id}/retirer", [], $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_la_vente_comptoir_ne_puise_que_dans_le_stock_local(): void
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'est_boutique' => true,
        ]);

        // Tout le stock est chez la sœur : ici, c'est une rupture.
        Stock::factory()->create([
            'produit_id' => $produit->id,
            'clinique_id' => $this->cliniqueSoeur->id,
            'quantite' => 10,
        ]);

        $this->postJson('/api/v1/boutique/vente', [
            'moyen_paiement' => 'cash',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes.0.quantite');
    }

    public function test_un_produit_d_une_autre_organisation_ne_se_vend_ni_ne_s_expose(): void
    {
        $etrangere = Clinique::factory()->create();
        $produitEtranger = Produit::factory()->create([
            'organisation_id' => $etrangere->societe->organisation_id,
            'est_boutique' => true,
        ]);

        $this->postJson('/api/v1/boutique/vente', [
            'moyen_paiement' => 'cash',
            'lignes' => [['produit_id' => $produitEtranger->id, 'quantite' => 1]],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes.0.produit_id');

        $this->patchJson("/api/v1/boutique/produits/{$produitEtranger->id}/vitrine", [
            'est_vedette' => true,
        ], $this->enTetes($this->jeton))->assertNotFound();
    }

    public function test_une_commande_creee_est_rattachee_a_la_clinique_du_jeton(): void
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'est_boutique' => true,
        ]);

        $reponse = $this->postJson('/api/v1/boutique/commandes', [
            'client_nom' => 'Marie Aubert',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ], $this->enTetes($this->jeton))->assertCreated();

        $commande = CommandeBoutique::withoutGlobalScopes()->find($reponse->json('data.id'));

        $this->assertSame($this->clinique->id, $commande->clinique_id);
    }
}
