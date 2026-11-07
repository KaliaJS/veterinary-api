<?php

namespace Tests\Feature\Site;

use App\Auth\RolesParDefaut;
use App\Enums\ServiceClinique;
use App\Models\Clinique;
use App\Models\Produit;
use App\Models\Role;
use App\Models\SiteWeb;
use App\Models\Stock;
use Database\Factories\SiteWebFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La boutique sur le site web public d'une clinique (choix B de Jim,
 * 03.11.2026) : servie sans compte, seulement les articles « Vendu en
 * boutique » de l'organisation du site, jamais un médicament sur
 * ordonnance ni un stupéfiant, sans prix d'achat ni marge, et le stock de
 * chaque clinique en mots plutôt qu'en quantités.
 */
class SiteShopTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private SiteWeb $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'ville' => 'Lausanne']);
        $this->site = SiteWebFactory::new()->publie()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'slug' => 'deux-rives',
        ]);
    }

    private function article(array $attributs = [], ?int $quantite = 8, ?Clinique $clinique = null): Produit
    {
        $clinique ??= $this->clinique;
        $produit = Produit::factory()->create([
            'organisation_id' => $clinique->societe->organisation_id,
            'categorie' => 'ali',
            'est_boutique' => true,
            'est_sur_ordonnance' => false,
            'prix' => 40,
            'cout' => 22,
            'promo_pct' => 0,
            ...$attributs,
        ]);
        if ($quantite !== null) {
            Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $clinique->id, 'quantite' => $quantite]);
        }

        return $produit;
    }

    // Case 1 : la page « Boutique » paraît seulement quand le service « Boutique » est coché

    public function test_le_site_dit_qu_il_a_une_boutique_quand_le_service_est_coche(): void
    {
        $this->article(['nom' => 'Croquettes rénales']);

        $this->getJson('/api/v1/public/sites/deux-rives')->assertOk()->assertJsonPath('data.has_shop', true);

        $this->clinique->services()->where('service', ServiceClinique::Boutique)->delete();

        $this->getJson('/api/v1/public/sites/deux-rives')->assertOk()->assertJsonPath('data.has_shop', false);
        $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertNotFound();
    }

    public function test_sans_article_a_vendre_le_site_n_a_pas_de_boutique(): void
    {
        // Seul un médicament sur ordonnance coché en boutique : rien à montrer au public
        $this->article(['nom' => 'Meloxicam', 'categorie' => 'med', 'est_sur_ordonnance' => true]);

        $this->getJson('/api/v1/public/sites/deux-rives')->assertOk()->assertJsonPath('data.has_shop', false);
    }

    // Case 2 : seuls les articles « Vendu en boutique » de l'organisation ; jamais un médicament sur ordonnance

    public function test_seuls_les_articles_vendus_en_boutique_de_l_organisation_sans_ordonnance_ni_stupefiant(): void
    {
        $croquettes = $this->article(['nom' => 'Croquettes rénales']);
        $this->article(['nom' => 'Meloxicam', 'categorie' => 'med', 'est_sur_ordonnance' => true]);
        $this->article(['nom' => 'Méthadone', 'categorie' => 'med', 'est_sur_ordonnance' => false, 'est_stupefiant' => true]);
        $this->article(['nom' => 'Hors vitrine', 'est_boutique' => false]);
        // Une autre organisation, avec son propre site et sa boutique
        $autre = Clinique::factory()->create();
        $this->article(['nom' => 'Article d’ailleurs'], 5, $autre);
        SiteWebFactory::new()->publie()->create(['organisation_id' => $autre->societe->organisation_id, 'slug' => 'ailleurs']);

        $reponse = $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk();

        $this->assertSame([$croquettes->id], array_column($reponse->json('data'), 'id'));
        $this->assertSame(['Article d’ailleurs'], array_column($this->getJson('/api/v1/public/sites/ailleurs/boutique')->json('data'), 'name'));
    }

    public function test_un_site_non_publie_ou_une_organisation_fermee_ne_sert_pas_sa_boutique(): void
    {
        $this->article();
        $this->site->update(['est_publie' => false]);
        $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertNotFound();

        $this->site->update(['est_publie' => true]);
        $this->clinique->societe->organisation->forceFill(['statut_abonnement' => null])->save();
        $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertNotFound();
    }

    // Case 3 : nom, prix, promotion, photo ; jamais le prix d'achat ni la marge

    public function test_chaque_article_a_son_nom_son_prix_sa_promotion_et_sa_photo_sans_prix_d_achat(): void
    {
        $this->article(['nom' => 'Harnais confort · Taille S', 'categorie' => 'acc', 'prix' => 29, 'promo_pct' => 15, 'photo_chemin' => 'produits/abc123.webp']);

        $article = $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk()->json('data.0');

        $this->assertSame('Harnais confort · Taille S', $article['name']);
        $this->assertSame('acc', $article['family']);
        $this->assertEquals(24.65, $article['price']);
        $this->assertEquals(29, $article['base_price']);
        $this->assertSame(15, $article['promo_pct']);
        $this->assertStringEndsWith('/public/photos-produits/abc123.webp', $article['photo_url']);
        foreach (['cout', 'cost', 'marge', 'margin', 'fournisseur', 'reference', 'ean', 'stock', 'quantite'] as $cle) {
            $this->assertArrayNotHasKey($cle, $article);
        }
        $this->assertStringNotContainsString('22', json_encode($article['stocks']));
    }

    public function test_les_articles_mis_en_avant_viennent_en_tete(): void
    {
        $this->article(['nom' => 'Aliment A']);
        $this->article(['nom' => 'Zeste, mis en avant', 'est_vedette' => true]);

        $this->assertSame(['Zeste, mis en avant', 'Aliment A'], array_column($this->getJson('/api/v1/public/sites/deux-rives/boutique')->json('data'), 'name'));
    }

    // Case 4 : les familles de la boutique en filtres

    public function test_les_familles_sont_celles_des_articles_en_vente(): void
    {
        $this->article(['categorie' => 'ali']);
        $this->article(['categorie' => 'acc']);
        $this->article(['categorie' => 'ali']);
        $this->article(['categorie' => 'med', 'est_sur_ordonnance' => true]);

        $this->assertSame(
            [['code' => 'ali', 'label' => 'Alimentation'], ['code' => 'acc', 'label' => 'Accessoires et jouets']],
            $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk()->json('meta.facettes'),
        );
    }

    // Case 5 : sa phrase, et où il est en stock (en mots, par clinique)

    public function test_la_phrase_et_le_stock_de_chaque_clinique_en_mots(): void
    {
        $annexe = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'nom' => 'Annexe de Pully', 'ville' => 'Pully']);
        $sansBoutique = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'nom' => 'Sans boutique']);
        $sansBoutique->services()->where('service', ServiceClinique::Boutique)->delete();

        $renal = $this->article(['nom' => 'Renal chien', 'shop_description' => 'Aliment diététique pour soutenir la fonction rénale.'], 9);
        Stock::factory()->create(['produit_id' => $renal->id, 'clinique_id' => $annexe->id, 'quantite' => 2]);
        Stock::factory()->create(['produit_id' => $renal->id, 'clinique_id' => $sansBoutique->id, 'quantite' => 7]);

        $article = $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk()->json('data.0');

        $this->assertSame('Aliment diététique pour soutenir la fonction rénale.', $article['description']);
        // Les cliniques qui ont coché la boutique, chacune nommée, avec son stock en mots
        $this->assertSame([
            ['clinic_id' => $this->clinique->id, 'name' => 'Deux Rives', 'city' => 'Lausanne', 'level' => 'in_stock', 'label' => 'En stock'],
            ['clinic_id' => $annexe->id, 'name' => 'Annexe de Pully', 'city' => 'Pully', 'level' => 'few', 'label' => 'Plus que quelques-uns'],
        ], $article['stocks']);
    }

    public function test_un_article_sans_stock_dans_une_clinique_y_est_epuise(): void
    {
        $this->article(['nom' => 'Kong Classic'], null);

        $this->assertSame(
            [['clinic_id' => $this->clinique->id, 'name' => 'Deux Rives', 'city' => 'Lausanne', 'level' => 'out', 'label' => 'Épuisé']],
            $this->getJson('/api/v1/public/sites/deux-rives/boutique')->json('data.0.stocks'),
        );
    }

    public function test_la_phrase_de_la_vitrine_se_regle_depuis_la_boutique(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $produit = $this->article();

        $this->patchJson("/api/v1/boutique/produits/{$produit->id}/vitrine", ['shop_description' => '  Le jouet à garnir qui occupe longtemps.  '], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.shop_description', 'Le jouet à garnir qui occupe longtemps.');

        $this->patchJson("/api/v1/boutique/produits/{$produit->id}/vitrine", ['shop_description' => str_repeat('a', 161)], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shop_description');

        $this->patchJson("/api/v1/boutique/produits/{$produit->id}/vitrine", ['shop_description' => ''], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.shop_description', null);
    }

    // Pagination (décision de Jim, 07.11.2026) : 24 articles par page, la forme habituelle de l'API

    public function test_le_catalogue_est_pagine_par_24(): void
    {
        foreach (range(1, 30) as $i) {
            $this->article(['nom' => sprintf('Article %02d', $i)]);
        }

        $premiere = $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk();
        $premiere->assertJsonCount(24, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 24)
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('data.0.name', 'Article 01');

        $this->getJson('/api/v1/public/sites/deux-rives/boutique?page=2')->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.name', 'Article 25');
        // Jamais plus de 100 d'un coup
        $this->getJson('/api/v1/public/sites/deux-rives/boutique?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_la_famille_se_filtre_au_serveur_et_les_familles_proposees_restent_celles_de_toute_la_boutique(): void
    {
        $this->article(['nom' => 'Croquettes', 'categorie' => 'ali']);
        $this->article(['nom' => 'Balle', 'categorie' => 'acc']);
        $this->article(['nom' => 'Laisse', 'categorie' => 'acc']);

        $reponse = $this->getJson('/api/v1/public/sites/deux-rives/boutique?famille=acc')->assertOk();

        $this->assertSame(['Balle', 'Laisse'], array_column($reponse->json('data'), 'name'));
        $reponse->assertJsonPath('meta.total', 2);
        $this->assertSame(['ali', 'acc'], array_column($reponse->json('meta.facettes'), 'code'));
        $this->getJson('/api/v1/public/sites/deux-rives/boutique?famille=nimporte')->assertUnprocessable()->assertJsonValidationErrors('famille');
        // Une famille qui n'a rien en vente : une page vide, pas une erreur
        $this->getJson('/api/v1/public/sites/deux-rives/boutique?famille=med')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_un_article_se_lit_seul_et_seulement_dans_la_vitrine_de_ce_site(): void
    {
        $kong = $this->article(['nom' => 'Kong Classic', 'categorie' => 'acc', 'shop_description' => 'Le jouet à garnir.']);
        $ordonnance = $this->article(['nom' => 'Meloxicam', 'categorie' => 'med', 'est_sur_ordonnance' => true]);
        $autre = Clinique::factory()->create();
        $ailleurs = $this->article(['nom' => 'D’ailleurs'], 5, $autre);

        $this->getJson("/api/v1/public/sites/deux-rives/boutique/articles/{$kong->id}")->assertOk()
            ->assertJsonPath('data.id', $kong->id)
            ->assertJsonPath('data.name', 'Kong Classic')
            ->assertJsonPath('data.description', 'Le jouet à garnir.')
            ->assertJsonPath('data.stocks.0.label', 'En stock')
            ->assertJsonMissingPath('data.cout');
        $this->getJson("/api/v1/public/sites/deux-rives/boutique/articles/{$ordonnance->id}")->assertNotFound();
        $this->getJson("/api/v1/public/sites/deux-rives/boutique/articles/{$ailleurs->id}")->assertNotFound();
    }

    // L'aperçu de l'éditeur : la vitrine de l'organisation du jeton, même avant publication

    public function test_l_editeur_lit_sa_vitrine_et_sait_s_il_a_une_boutique_avant_publication(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)
            ->value('id')]);
        $this->site->update(['est_publie' => false]);
        $this->article(['nom' => 'Croquettes', 'categorie' => 'ali']);
        $autre = Clinique::factory()->create();
        $this->article(['nom' => 'D’ailleurs'], 5, $autre);

        $this->getJson('/api/v1/site', $this->enTetes($jeton))->assertOk()->assertJsonPath('data.has_shop', true);
        $reponse = $this->getJson('/api/v1/site/boutique?famille=ali', $this->enTetes($jeton))->assertOk();
        $this->assertSame(['Croquettes'], array_column($reponse->json('data'), 'name'));
        $reponse->assertJsonPath('meta.facettes.0.code', 'ali');
        $this->getJson('/api/v1/site/boutique')->assertUnauthorized();
    }

    public function test_l_apercu_demande_le_droit_organisation_et_ne_montre_que_sa_propre_organisation(): void
    {
        $this->article(['nom' => 'Croquettes de Deux Rives']);
        $autre = Clinique::factory()->create();
        $this->article(['nom' => 'Laisse du Lac'], 4, $autre);
        ['jeton' => $sansDroit] = $this->employeAvecJeton($this->clinique);
        ['employe' => $admin, 'jeton' => $jeton] = $this->employeAvecJeton($autre);
        $admin->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $autre->societe->organisation_id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)
            ->value('id')]);

        // Un employé sans le droit Organisation : refusé
        $this->getJson('/api/v1/site/boutique', $this->enTetes($sansDroit))->assertForbidden();

        // L'administrateur d'une autre organisation ne voit que la sienne
        $reponse = $this->getJson('/api/v1/site/boutique', $this->enTetes($jeton))->assertOk();
        $this->assertSame(['Laisse du Lac'], array_column($reponse->json('data'), 'name'));
        $this->assertSame([$autre->id], array_column($reponse->json('data.0.stocks'), 'clinic_id'));
    }

    // Isolement : un débit limité, à part de l'annuaire et des autres pages publiques

    public function test_le_debit_est_limite(): void
    {
        $this->article();

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk();
        }
        $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertStatus(429);
        // Son compteur à part : le site lui-même reste servi
        $this->getJson('/api/v1/public/sites/deux-rives')->assertOk();
    }
}
