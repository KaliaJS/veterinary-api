<?php

namespace Tests\Feature\Stocks;

use App\Enums\StatutCommandeFournisseur;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Lot;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Cycle d'un bon de commande : brouillon → envoyée → réceptionnée. La
 * réception incrémente le stock de la clinique qui commande, trace les
 * mouvements et crée un lot par article périssable ; la génération
 * automatique regroupe le stock bas par fournisseur.
 */
class CommandeFournisseurTest extends TestCase
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

    private function produit(array $attributs = []): Produit
    {
        return Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            ...$attributs,
        ]);
    }

    public function test_creer_une_commande_numerote_et_fige_le_prix_d_achat(): void
    {
        $produit = $this->produit(['cout' => 13.4]);

        $reponse = $this->postJson('/api/v1/stocks/commandes', [
            'fournisseur' => 'Vetopharm SA',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 12]],
        ], $this->enTetes($this->jeton));

        $annee = aujourdhui()->year;

        $reponse->assertCreated()
            ->assertJsonPath('data.numero', "BC-{$annee}-001")
            ->assertJsonPath('data.statut', 'draft')
            ->assertJsonPath('data.lignes.0.cout', 13.4);
    }

    public function test_envoyer_un_brouillon_horodate_et_change_le_statut(): void
    {
        $commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create([
            'commande_fournisseur_id' => $commande->id,
            'produit_id' => $this->produit()->id,
        ]);

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'sent');

        $this->assertNotNull($commande->fresh()->envoyee_le);
    }

    public function test_envoyer_une_commande_sans_ligne_est_refuse(): void
    {
        $commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes');
    }

    public function test_la_reception_incremente_le_stock_journalise_et_cree_un_lot(): void
    {
        $produit = $this->produit(['peremption_le' => now()->addYear()->startOfMonth()]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 2]);

        $commande = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create([
            'commande_fournisseur_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 10,
        ]);

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'rec');

        $this->assertSame(12, Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->sole()->quantite);
        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => 10,
            'libelle' => 'Réception '.$commande->numero,
        ]);
        $this->assertDatabaseHas('lots', [
            'produit_id' => $produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => 10,
        ]);
    }

    /** Un stupéfiant reçu entre par le registre, signé par qui réceptionne, avec son droit (24.10.2026). */
    public function test_un_stupefiant_recu_s_inscrit_au_registre(): void
    {
        $produit = $this->produit(['est_stupefiant' => true, 'peremption_le' => now()->addYear()->startOfMonth()]);
        $commande = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 10]);

        // Sans le droit des stupéfiants : rien n'entre, la commande reste envoyée
        ['jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->enTetes($jetonAsv))->assertForbidden();
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $commande->refresh()->statut);
        $this->assertSame(0, MouvementStupefiant::withoutGlobalScopes()->count());

        ['employe' => $veto, 'jeton' => $jetonVeto] = $this->employeAvecJeton($this->clinique);
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->enTetes($jetonVeto))->assertOk();

        $inscription = MouvementStupefiant::withoutGlobalScopes()->sole();
        $this->assertSame('10.00', $inscription->quantite);
        $this->assertSame('Réception '.$commande->numero, $inscription->motif);
        $this->assertSame($veto->id, $inscription->employe_id);
        $this->assertNull($inscription->patient_id);
        $this->assertSame(10, Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->sole()->quantite);
        $this->assertDatabaseHas('lots', ['produit_id' => $produit->id, 'quantite' => 10]);
    }

    public function test_un_article_sans_peremption_ne_cree_pas_de_lot_a_la_reception(): void
    {
        $produit = $this->produit(['peremption_le' => null]);
        $commande = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create([
            'commande_fournisseur_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 5,
        ]);

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(0, Lot::withoutGlobalScopes()->count());
    }

    public function test_receptionner_un_brouillon_est_refuse(): void
    {
        $commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');
    }

    public function test_generer_regroupe_le_stock_bas_par_fournisseur_en_brouillons(): void
    {
        $bas1 = $this->produit(['fournisseur' => 'Vetopharm SA', 'seuil_alerte' => 6]);
        Stock::factory()->create(['produit_id' => $bas1->id, 'clinique_id' => $this->clinique->id, 'quantite' => 2]);

        $bas2 = $this->produit(['fournisseur' => 'Zoevet AG', 'seuil_alerte' => 4]);
        Stock::factory()->create(['produit_id' => $bas2->id, 'clinique_id' => $this->clinique->id, 'quantite' => 0]);

        $sain = $this->produit(['fournisseur' => 'Vetopharm SA', 'seuil_alerte' => 3]);
        Stock::factory()->create(['produit_id' => $sain->id, 'clinique_id' => $this->clinique->id, 'quantite' => 20]);

        $reponse = $this->postJson('/api/v1/stocks/commandes/generer', [], $this->enTetes($this->jeton));

        $reponse->assertOk()->assertJsonCount(2, 'data');

        // Remonter à deux fois le seuil : 6 × 2 − 2 = 10.
        $commande = CommandeFournisseur::query()->where('fournisseur', 'Vetopharm SA')->sole();
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $commande->statut);
        $this->assertSame(10, $commande->lignes()->sole()->quantite);
    }

    public function test_generer_ignore_les_articles_deja_couverts_par_une_commande_ouverte(): void
    {
        $produit = $this->produit(['fournisseur' => 'Vetopharm SA', 'seuil_alerte' => 6]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 1]);

        $ouverte = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create([
            'commande_fournisseur_id' => $ouverte->id,
            'produit_id' => $produit->id,
        ]);

        $this->postJson('/api/v1/stocks/commandes/generer', [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commandes');
    }

    /** Stock bas par clinique (décision de Jim, 28.10.2026) : le stock de la clinique ouverte face au seuil, même si une sœur en a beaucoup. */
    public function test_generer_compare_le_stock_de_la_clinique_ouverte_au_seuil(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $produit = $this->produit(['fournisseur' => 'Vetopharm SA', 'seuil_alerte' => 10]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 2]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $soeur->id, 'quantite' => 40]);

        $this->postJson('/api/v1/stocks/commandes/generer', [], $this->enTetes($this->jeton))->assertOk()->assertJsonCount(1, 'data');

        // Remonter le stock de la clinique à deux fois le seuil : 10 × 2 − 2 = 18
        $this->assertSame(18, CommandeFournisseur::query()->sole()->lignes()->sole()->quantite);
    }

    /** « Seules les commandes ouvertes » : une commande déjà réceptionnée de la clinique ne couvre plus l'article. */
    public function test_une_commande_receptionnee_ne_couvre_plus_l_article(): void
    {
        $produit = $this->produit(['fournisseur' => 'Vetopharm SA', 'seuil_alerte' => 10]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 0]);
        $recue = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id, 'statut' => StatutCommandeFournisseur::Receptionnee]);
        CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $recue->id, 'produit_id' => $produit->id]);

        $this->postJson('/api/v1/stocks/commandes/generer', [], $this->enTetes($this->jeton))->assertOk()->assertJsonCount(1, 'data');
    }

    /** Un brouillon d'une clinique sœur ne couvre plus l'article d'ici. */
    public function test_le_brouillon_d_une_clinique_soeur_ne_bloque_plus_la_generation(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $produit = $this->produit(['fournisseur' => 'Vetopharm SA', 'seuil_alerte' => 10]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 0]);
        $brouillonSoeur = CommandeFournisseur::factory()->create(['clinique_id' => $soeur->id]);
        CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $brouillonSoeur->id, 'produit_id' => $produit->id]);

        $this->postJson('/api/v1/stocks/commandes/generer', [], $this->enTetes($this->jeton))->assertOk()->assertJsonCount(1, 'data');
    }

    /** Un article bien fourni ici n'est pas commandé, même si la somme de l'organisation est sous le seuil. */
    public function test_un_article_bien_fourni_ici_n_est_pas_commande(): void
    {
        $produit = $this->produit(['fournisseur' => 'Vetopharm SA', 'seuil_alerte' => 10]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 11]);

        $this->postJson('/api/v1/stocks/commandes/generer', [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    /** Un article que la clinique ne tient pas (stock seulement chez une sœur) n'est pas commandé ici. */
    public function test_un_article_que_la_clinique_ne_tient_pas_n_est_pas_commande(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $produit = $this->produit(['seuil_alerte' => 10]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $soeur->id, 'quantite' => 0]);

        $this->postJson('/api/v1/stocks/commandes/generer', [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    /** L'article dit s'il est sous le seuil dans la clinique ouverte (l'écran compte comme la génération). */
    public function test_l_article_dit_s_il_est_sous_le_seuil_dans_la_clinique_ouverte(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $produit = $this->produit(['seuil_alerte' => 10]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 2]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $soeur->id, 'quantite' => 40]);

        $this->getJson('/api/v1/stocks/produits', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.sous_seuil_clinique', true)
            ->assertJsonPath('data.0.sous_seuil', false);
    }

    /** On commande depuis la clinique (décision de Jim, 28.10.2026) : plus de commande ni de brouillons automatiques pour une clinique sœur. */
    public function test_on_ne_commande_plus_pour_une_clinique_soeur(): void
    {
        $cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $produit = $this->produit(['seuil_alerte' => 10, 'fournisseur' => 'Vetopharm SA']);
        // Tenu par la clinique ouverte, en rupture (stock bas par clinique, 28.10.2026)
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 0]);
        $refus = 'Une commande se passe depuis la clinique qui la reçoit : ouvrez cette clinique pour commander.';

        $this->postJson('/api/v1/stocks/commandes', [
            'fournisseur' => 'Vetopharm SA', 'clinique_id' => $cliniqueSoeur->id, 'lignes' => [['produit_id' => $produit->id, 'quantite' => 6]],
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors(['clinique_id' => $refus]);
        $this->postJson('/api/v1/stocks/commandes/generer', ['clinique_id' => $cliniqueSoeur->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['clinique_id' => $refus]);
        $this->assertSame(0, CommandeFournisseur::withoutGlobalScopes()->count());

        // Sans clinique demandée : pour la clinique ouverte
        $this->postJson('/api/v1/stocks/commandes/generer', [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.0.clinique_id', $this->clinique->id);
    }

    /** La clinique de livraison d'un brouillon ne se change plus. */
    public function test_la_clinique_d_un_brouillon_ne_se_change_plus(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->patchJson("/api/v1/stocks/commandes/{$commande->id}", ['clinique_id' => $soeur->id], $this->enTetes($this->jeton))->assertStatus(405);

        $this->assertSame($this->clinique->id, $commande->refresh()->clinique_id);
    }

    public function test_les_lignes_d_un_brouillon_s_ajoutent_se_modifient_et_se_retirent(): void
    {
        $a = $this->produit(['cout' => 4.5]);
        $b = $this->produit(['cout' => 12]);
        $commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);

        $ligneA = $this->postJson("/api/v1/stocks/commandes/{$commande->id}/lignes", ['produit_id' => $a->id, 'quantite' => 3], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.lignes')
            ->assertJsonPath('data.lignes.0.produit_id', $a->id)
            ->assertJsonPath('data.lignes.0.quantite', 3)
            ->assertJsonPath('data.lignes.0.cout', 4.5)
            ->json('data.lignes.0.id');

        // Un article déjà présent : pas de doublon.
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/lignes", ['produit_id' => $a->id, 'quantite' => 1], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('produit_id');

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/lignes", ['produit_id' => $b->id, 'quantite' => 2], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data.lignes');

        $this->patchJson("/api/v1/stocks/commandes/{$commande->id}/lignes/{$ligneA}", ['quantite' => 9], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.lignes.0.quantite', 9);

        $this->patchJson("/api/v1/stocks/commandes/{$commande->id}/lignes/{$ligneA}", ['quantite' => 0], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantite');

        $this->deleteJson("/api/v1/stocks/commandes/{$commande->id}/lignes/{$ligneA}", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.lignes')
            ->assertJsonPath('data.lignes.0.produit_id', $b->id);

        $this->assertDatabaseMissing('commande_fournisseur_lignes', ['id' => $ligneA]);
    }

    public function test_les_lignes_d_une_commande_envoyee_sont_figees(): void
    {
        $produit = $this->produit();
        $commande = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        $ligne = CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commande->id, 'quantite' => 2]);

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/lignes", ['produit_id' => $produit->id, 'quantite' => 1], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->patchJson("/api/v1/stocks/commandes/{$commande->id}/lignes/{$ligne->id}", ['quantite' => 5], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->deleteJson("/api/v1/stocks/commandes/{$commande->id}/lignes/{$ligne->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('statut');

        $this->assertSame(2, $ligne->fresh()->quantite);
    }

    public function test_une_ligne_d_une_autre_commande_est_introuvable_via_ce_brouillon(): void
    {
        $brouillon = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);
        $autre = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);
        $ligneAutre = CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $autre->id]);

        $this->patchJson("/api/v1/stocks/commandes/{$brouillon->id}/lignes/{$ligneAutre->id}", ['quantite' => 5], $this->enTetes($this->jeton))
            ->assertNotFound();
        $this->deleteJson("/api/v1/stocks/commandes/{$brouillon->id}/lignes/{$ligneAutre->id}", [], $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_supprimer_un_brouillon_est_tolere(): void
    {
        $commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->deleteJson("/api/v1/stocks/commandes/{$commande->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('commandes_fournisseur', ['id' => $commande->id]);
    }

    public function test_supprimer_une_commande_receptionnee_est_refuse(): void
    {
        $commande = CommandeFournisseur::factory()->receptionnee()->create(['clinique_id' => $this->clinique->id]);

        $this->deleteJson("/api/v1/stocks/commandes/{$commande->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statut' => 'Une commande réceptionnée a fait bouger le stock, elle ne se supprime plus.']);

        $this->assertDatabaseHas('commandes_fournisseur', ['id' => $commande->id]);
    }
}
