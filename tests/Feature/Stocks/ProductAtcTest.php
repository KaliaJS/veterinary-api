<?php

namespace Tests\Feature\Stocks;

use App\Models\Clinique;
use App\Models\Produit;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le code ATC d'un article du stock (dossier patient, mise en garde des
 * allergies, 03.11.2026) : il se modifie sur un article existant (droit du
 * stock), il n'accepte qu'un groupe ATC réel, et les articles déjà en
 * stock le reprennent du catalogue Swissmedic par leur code-barres.
 */
class ProductAtcTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
    }

    private function article(array $attributs = []): Produit
    {
        return Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, ...$attributs]);
    }

    private function catalogue(string $gtin, string $atc): void
    {
        $produit = ProduitVeterinaire::query()->create(['numero_autorisation' => substr($gtin, 4, 5), 'nom' => 'Produit '.$atc, 'nom_court' => 'Produit', 'categorie' => 'medicament', 'atc_code' => $atc]);
        ProduitEmballage::query()->create(['produit_id' => $produit->id, 'numero_emballage' => substr($gtin, 4, 8), 'gtin' => $gtin]);
    }

    public function test_le_code_atc_d_un_article_existant_se_modifie(): void
    {
        ['jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        $article = $this->article(['atc_code' => null]);

        $this->patchJson("/api/v1/stocks/produits/{$article->id}/atc", ['atc_code' => 'qm01 ac06'], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.atc_code', 'QM01AC06');
        $this->assertSame('QM01AC06', $article->fresh()->atc_code);

        $this->patchJson("/api/v1/stocks/produits/{$article->id}/atc", ['atc_code' => null], $this->enTetes($jeton))->assertOk();
        $this->assertNull($article->fresh()->atc_code);
    }

    public function test_seul_le_droit_du_stock_modifie_le_code(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);
        $article = $this->article();

        $this->patchJson("/api/v1/stocks/produits/{$article->id}/atc", ['atc_code' => 'QM01AC06'], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_seul_un_groupe_atc_reel_est_accepte(): void
    {
        ['jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        $article = $this->article();
        $url = "/api/v1/stocks/produits/{$article->id}/atc";

        foreach (['QM01AC06', 'J01CA04', 'QI07AI', 'QP53BE', 'N01AX03'] as $bon) {
            $this->patchJson($url, ['atc_code' => $bon], $this->enTetes($jeton))->assertOk();
        }
        foreach (['E01AA', 'QZ01', 'K01', 'I07AI', 'QQ01', '12AB'] as $mauvais) {
            $this->patchJson($url, ['atc_code' => $mauvais], $this->enTetes($jeton))->assertUnprocessable();
        }
        $this->postJson('/api/v1/stocks/produits', ['nom' => 'X', 'categorie' => 'med', 'prix' => 1, 'atc_code' => 'E01AA'], $this->enTetes($jeton))->assertUnprocessable();
    }

    public function test_les_articles_en_stock_reprennent_le_code_du_catalogue(): void
    {
        $this->catalogue('7680654320014', 'QM01AC06');
        $sansCode = $this->article(['ean' => '7680654320014', 'atc_code' => null]);
        $dejaSaisi = $this->article(['ean' => '7680654320014', 'atc_code' => 'QM01AC05']);
        $inconnu = $this->article(['ean' => '7680000000000', 'atc_code' => null]);

        $this->artisan('produits:atc-depuis-catalogue')->assertSuccessful();

        $this->assertSame('QM01AC06', $sansCode->fresh()->atc_code);
        $this->assertSame('QM01AC05', $dejaSaisi->fresh()->atc_code);
        $this->assertNull($inconnu->fresh()->atc_code);
    }

    /** M2 a : sans corps, refusé ; il faut `atc_code: null` pour effacer */
    public function test_un_patch_sans_corps_n_efface_jamais_le_code(): void
    {
        ['jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        $article = $this->article(['atc_code' => 'QM01AC06']);

        $this->patchJson("/api/v1/stocks/produits/{$article->id}/atc", [], $this->enTetes($jeton))->assertUnprocessable();
        $this->assertSame('QM01AC06', $article->fresh()->atc_code);
    }

    /** M2 b : un code effacé exprès ne revient pas du catalogue */
    public function test_un_code_efface_a_la_main_ne_revient_pas_du_catalogue(): void
    {
        ['jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        $this->catalogue('7680654320014', 'QM01AC06');
        $article = $this->article(['ean' => '7680654320014', 'atc_code' => 'QM01AC06']);

        $this->patchJson("/api/v1/stocks/produits/{$article->id}/atc", ['atc_code' => null], $this->enTetes($jeton))->assertOk();
        $this->artisan('produits:atc-depuis-catalogue')->assertSuccessful();

        $this->assertNull($article->fresh()->atc_code);
        $this->assertTrue((bool) $article->fresh()->atc_code_is_manual);
    }

    /** M2 c : un code mal tapé, expliqué en français */
    public function test_un_code_mal_tape_est_explique_en_francais(): void
    {
        ['jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        $article = $this->article();

        $message = $this->patchJson("/api/v1/stocks/produits/{$article->id}/atc", ['atc_code' => 'E01AA'], $this->enTetes($jeton))
            ->assertUnprocessable()->json('errors.atc_code.0');
        $this->assertStringContainsString('code ATC', $message);
        $this->assertStringContainsString('QM01AC06', $message);
    }

    /** La fenêtre de création envoie un champ vide : le code vient du catalogue, et n'est pas « saisi à la main » */
    public function test_un_code_vide_a_la_creation_est_repris_du_catalogue(): void
    {
        $this->catalogue('7680654320014', 'QM01AC06');
        ['jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);

        $id = $this->postJson('/api/v1/stocks/produits', ['nom' => 'Metacam', 'categorie' => 'med', 'prix' => 20, 'ean' => '7680654320014', 'atc_code' => null], $this->enTetes($jeton))
            ->assertCreated()
            ->json('data.id');

        $produit = Produit::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('QM01AC06', $produit->atc_code);
        $this->assertFalse((bool) $produit->atc_code_is_manual);
    }
}
