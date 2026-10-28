<?php

namespace Tests\Feature\Boutique;

use App\Enums\StatutFacture;
use App\Mail\CommandeBoutiquePrete;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\CommandeBoutiqueLigne;
use App\Models\Facture;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
            'est_sur_ordonnance' => false,
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

    public function test_un_identifiant_envoye_en_tableau_est_refuse_proprement(): void
    {
        $produit = $this->produitEnRayon(10);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        // Un tableau d'identifiants existants passait `exists` : la fiche devenait une collection
        $this->postJson('/api/v1/boutique/commandes', [
            'client_id' => [$client->id],
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->postJson('/api/v1/boutique/commandes', [
            'client_nom' => 'Marie Aubert',
            'lignes' => [['produit_id' => [$produit->id], 'quantite' => 1]],
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('lignes.0.produit_id');

        $this->assertSame(0, CommandeBoutique::query()->count());
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

    /** Commande prête : le client est prévenu par e-mail (adresse de sa fiche, sinon de son compte), et la réponse le dit. */
    public function test_une_commande_prete_previent_le_client_par_e_mail(): void
    {
        Mail::fake();
        $organisation = $this->clinique->societe->organisation_id;
        $avecFiche = Client::factory()->create(['organisation_id' => $organisation, 'email' => 'lea@exemple.ch']);
        $compte = User::factory()->create(['email' => 'paul@exemple.ch']);
        $avecCompte = Client::factory()->create(['organisation_id' => $organisation, 'email' => null, 'user_id' => $compte->id]);
        $sansAdresse = Client::factory()->create(['organisation_id' => $organisation, 'email' => null, 'user_id' => null]);
        $entetes = $this->enTetes($this->jeton);

        foreach ([[$avecFiche, 'lea@exemple.ch'], [$avecCompte, 'paul@exemple.ch'], [$sansAdresse, null]] as [$client, $adresse]) {
            $commande = CommandeBoutique::factory()->create(['clinique_id' => $this->clinique->id, 'client_id' => $client->id]);

            $this->postJson("/api/v1/boutique/commandes/{$commande->id}/preparer", [], $entetes)
                ->assertOk()->assertJsonPath('data.client_prevenu', $adresse !== null);

            if ($adresse !== null) {
                Mail::assertSent(CommandeBoutiquePrete::class, fn (CommandeBoutiquePrete $mail) => $mail->hasTo($adresse) && $mail->commande->is($commande));
            }
        }
        Mail::assertSent(CommandeBoutiquePrete::class, 2);
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
        $this->postJson("/api/v1/boutique/commandes/{$commande->id}/retirer", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Seule une commande prête se remet au client.']);
    }

    public function test_la_remise_refuse_la_rupture_de_stock_sans_sortie_partielle(): void
    {
        $produit = $this->produitEnRayon(1);
        $commande = CommandeBoutique::factory()->create(['clinique_id' => $this->clinique->id]);
        CommandeBoutiqueLigne::factory()->create([
            'commande_boutique_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 2,
        ]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/boutique/commandes/{$commande->id}/preparer", [], $entetes)->assertOk();

        $this->postJson("/api/v1/boutique/commandes/{$commande->id}/retirer", [], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.quantite']);

        $this->assertSame(1, $this->stockDe($produit));
        $this->assertSame('ready', $commande->fresh()->statut->value);
        $this->assertDatabaseMissing('mouvements_stock', ['produit_id' => $produit->id]);
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

    /** La vente comptoir se rattache à une fiche client de l'organisation ; une fiche d'ailleurs est refusée. */
    public function test_la_vente_comptoir_se_rattache_a_une_fiche_client(): void
    {
        $produit = $this->produitEnRayon(5);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Léa', 'nom' => 'Martin']);
        $etranger = Client::factory()->create();

        $this->postJson('/api/v1/boutique/vente', [
            'client_id' => $etranger->id, 'moyen_paiement' => 'twint', 'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $id = $this->postJson('/api/v1/boutique/vente', [
            'client_id' => $client->id, 'moyen_paiement' => 'twint', 'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ], $this->enTetes($this->jeton))->assertCreated()->assertJsonPath('data.client_id', $client->id)->json('data.id');

        $facture = Facture::withoutGlobalScopes()->find($id);
        $this->assertSame($client->id, $facture->client_id);
        $this->assertSame($client->refresh()->nom_affiche, $facture->owner_name);
    }

    /** Aperçu du ticket avant l'encaissement : lignes, total, TVA et HT servis, du même calcul que la facture, sans rien écrire. */
    public function test_l_apercu_du_ticket_est_celui_de_la_facture(): void
    {
        $produit = $this->produitEnRayon(5, ['promo_pct' => 10]);
        $panier = ['lignes' => [['produit_id' => $produit->id, 'quantite' => 3]]];

        $apercu = $this->postJson('/api/v1/boutique/vente/apercu', $panier, $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame(0, Facture::withoutGlobalScopes()->count());
        $this->assertSame(5, $this->stockDe($produit));

        $facture = $this->postJson('/api/v1/boutique/vente', [...$panier, 'moyen_paiement' => 'twint'], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertEquals($facture['total_ttc'], $apercu['total_ttc']);
        $this->assertEquals($facture['montant_tva'], $apercu['montant_tva']);
        $this->assertEquals($facture['taux_tva'], $apercu['taux_tva']);
        $this->assertEquals([['produit_id' => $produit->id, 'quantite' => 3, 'prix_chf' => 27, 'total' => 81]], $apercu['lignes']);
    }

    /** Chiffres de la boutique : les ventes se comptent en tickets (factures), pas en lignes ; le panier moyen est servi. */
    public function test_les_chiffres_comptent_les_tickets_et_servent_le_panier_moyen(): void
    {
        $croquettes = $this->produitEnRayon(10);
        $shampooing = $this->produitEnRayon(10);
        $entetes = $this->enTetes($this->jeton);
        // Un ticket de deux lignes, puis un ticket d'une ligne
        $this->postJson('/api/v1/boutique/vente', ['moyen_paiement' => 'twint', 'lignes' => [['produit_id' => $croquettes->id, 'quantite' => 1], ['produit_id' => $shampooing->id, 'quantite' => 1]]], $entetes)->assertCreated();
        $this->postJson('/api/v1/boutique/vente', ['moyen_paiement' => 'twint', 'lignes' => [['produit_id' => $croquettes->id, 'quantite' => 2]]], $entetes)->assertCreated();

        $totaux = $this->getJson('/api/v1/boutique/statistiques', $entetes)->assertOk()->json('data.totaux');

        $this->assertSame(2, $totaux['tickets']);
        $this->assertEquals(round($totaux['chiffre_affaires'] / 2, 2), $totaux['panier_moyen']);
    }

    public function test_ni_ordonnance_ni_stupefiant_au_comptoir_ni_en_commande(): void
    {
        $surOrdonnance = $this->produitEnRayon(5, ['nom' => 'Antibiotique', 'est_sur_ordonnance' => true]);
        $stupefiant = $this->produitEnRayon(5, ['nom' => 'Kétamine', 'est_stupefiant' => true]);

        foreach ([$surOrdonnance, $stupefiant] as $produit) {
            $this->postJson('/api/v1/boutique/vente', [
                'moyen_paiement' => 'cash',
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            ], $this->enTetes($this->jeton))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('lignes.0.produit_id');

            $this->postJson('/api/v1/boutique/commandes', [
                'client_nom' => 'Mme Pineau',
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            ], $this->enTetes($this->jeton))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('lignes.0.produit_id');

            $this->assertSame(5, $this->stockDe($produit));
        }
        $this->assertSame(0, Facture::withoutGlobalScopes()->count());

        // Et jamais un stupéfiant en vitrine
        $stupefiant->update(['est_boutique' => false]);
        $this->patchJson("/api/v1/boutique/produits/{$stupefiant->id}/vitrine", ['est_boutique' => true], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('est_boutique');
    }

    public function test_un_article_passe_sur_ordonnance_ne_se_remet_plus(): void
    {
        $produit = $this->produitEnRayon(5);
        $id = $this->postJson('/api/v1/boutique/commandes', [
            'client_nom' => 'Mme Pineau',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/boutique/commandes/{$id}/preparer", [], $this->enTetes($this->jeton))->assertOk();

        $produit->update(['est_sur_ordonnance' => true]);
        $this->postJson("/api/v1/boutique/commandes/{$id}/retirer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes.0.produit_id');
        $this->assertSame(5, $this->stockDe($produit));
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

    /** Les 7 jours commencent à minuit à Zurich, pas en UTC (24.10.2026). */
    public function test_les_7_jours_commencent_a_minuit_a_l_heure_de_la_clinique(): void
    {
        $this->clinique->update(['fuseau_horaire' => 'Europe/Zurich']);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $produit = $this->produitEnRayon(10, ['prix' => 30, 'cout' => 12]);
        $vente = function (string $quand, int $quantite) use ($produit): void {
            $facture = Facture::factory()->payee()->create(['clinique_id' => $this->clinique->id, 'payee_le' => CarbonImmutable::parse($quand, 'Europe/Zurich')->utc()]);
            $facture->lignes()->create(['produit_id' => $produit->id, 'libelle' => $produit->nom, 'quantite' => $quantite, 'prix_chf' => 30, 'type' => 'med']);
        };
        // Le premier jour de la période, juste après minuit (encore la veille en UTC)
        $vente('2026-11-08 00:30', 1);
        // La veille au soir : hors période
        $vente('2026-11-07 23:30', 4);

        $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.ventes')
            ->assertJsonPath('data.ventes.0.quantite', 1)
            ->assertJsonPath('data.ventes.0.jour', '2026-11-08');
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
