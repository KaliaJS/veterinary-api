<?php

namespace Tests\Feature\Site;

use App\Enums\ServiceClinique;
use App\Models\Clinique;
use App\Models\Produit;
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

        $this->assertSame([$croquettes->id], array_column($reponse->json('data.articles'), 'id'));
        $this->assertSame(['Article d’ailleurs'], array_column($this->getJson('/api/v1/public/sites/ailleurs/boutique')->json('data.articles'), 'name'));
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

        $article = $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk()->json('data.articles.0');

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

        $this->assertSame(['Zeste, mis en avant', 'Aliment A'], array_column($this->getJson('/api/v1/public/sites/deux-rives/boutique')->json('data.articles'), 'name'));
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
            $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk()->json('data.families'),
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

        $data = $this->getJson('/api/v1/public/sites/deux-rives/boutique')->assertOk()->json('data');

        $this->assertSame([
            ['id' => $this->clinique->id, 'name' => 'Deux Rives', 'city' => 'Lausanne'],
            ['id' => $annexe->id, 'name' => 'Annexe de Pully', 'city' => 'Pully'],
        ], $data['clinics']);
        $this->assertSame('Aliment diététique pour soutenir la fonction rénale.', $data['articles'][0]['description']);
        $this->assertSame([
            ['clinic_id' => $this->clinique->id, 'level' => 'in_stock', 'label' => 'En stock'],
            ['clinic_id' => $annexe->id, 'level' => 'few', 'label' => 'Plus que quelques-uns'],
        ], $data['articles'][0]['stocks']);
    }

    public function test_un_article_sans_stock_dans_une_clinique_y_est_epuise(): void
    {
        $this->article(['nom' => 'Kong Classic'], null);

        $this->assertSame(
            [['clinic_id' => $this->clinique->id, 'level' => 'out', 'label' => 'Épuisé']],
            $this->getJson('/api/v1/public/sites/deux-rives/boutique')->json('data.articles.0.stocks'),
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
