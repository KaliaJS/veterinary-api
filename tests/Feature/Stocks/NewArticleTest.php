<?php

namespace Tests\Feature\Stocks;

use App\Models\Clinique;
use App\Models\Lot;
use App\Models\Produit;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La fenêtre « Nouvel article », proposition B (choix de Jim, 03.11.2026) :
 * ce que l'API reçoit et rend pour chaque case de la todo. La recherche
 * générale du catalogue Swissmedic sert l'étape 1 « Quoi ».
 */
class NewArticleTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function creer(array $donnees): TestResponse
    {
        return $this->postJson('/api/v1/stocks/produits', ['prix' => 10, ...$donnees], $this->enTetes($this->jeton));
    }

    private function catalogue(): void
    {
        $metacam = ProduitVeterinaire::query()->create([
            'numero_autorisation' => '54321', 'nom' => 'Metacam 1,5 mg/ml ad us. vet., suspension orale', 'nom_court' => 'Metacam 1,5 mg/ml',
            'atc_code' => 'QM01AC06', 'categorie' => ProduitVeterinaire::MEDICAMENT, 'categorie_remise' => 'B', 'substances' => ['meloxicamum'],
        ]);
        ProduitEmballage::query()->create(['produit_id' => $metacam->id, 'numero_emballage' => '001', 'taille' => '100', 'unite' => 'ml', 'gtin' => '7680543210017', 'nom_fr' => 'Metacam 1,5 mg/ml susp orale chien 100 ml']);
        $bravecto = ProduitVeterinaire::query()->create([
            'numero_autorisation' => '65432', 'nom' => 'Bravecto 500 mg ad us. vet.', 'nom_court' => 'Bravecto 500 mg',
            'atc_code' => 'QP53BE02', 'categorie' => ProduitVeterinaire::MEDICAMENT, 'categorie_remise' => 'D',
        ]);
        ProduitEmballage::query()->create(['produit_id' => $bravecto->id, 'numero_emballage' => '002', 'taille' => '1', 'unite' => 'Tablette(n)', 'gtin' => '7680654320012', 'nom_fr' => 'Bravecto cpr à croquer chien 10-20 kg']);
    }

    /** Étape 1 : chercher dans le catalogue Swissmedic, par code-barres et par nom. */
    public function test_le_catalogue_swissmedic_se_cherche_par_code_barres_et_par_nom(): void
    {
        $this->catalogue();

        $parCode = $this->getJson('/api/v1/catalogue/produits?q=7680543210017', $this->enTetes($this->jeton))->assertOk();
        $parCode->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gtin', '7680543210017')
            ->assertJsonPath('data.0.name', 'Metacam 1,5 mg/ml susp orale chien 100 ml')
            ->assertJsonPath('data.0.atc_code', 'QM01AC06')
            ->assertJsonPath('data.0.is_prescription', true)
            ->assertJsonPath('data.0.category', 'med')
            ->assertJsonPath('data.0.content', 100)
            ->assertJsonPath('data.0.dose_unit', 'ml');

        // Par nom ; un antiparasitaire (ATC QP) est proposé dans sa famille, sans ordonnance (catégorie D)
        $parNom = $this->getJson('/api/v1/catalogue/produits?q=bravecto', $this->enTetes($this->jeton))->assertOk();
        $parNom->assertJsonPath('data.0.gtin', '7680654320012')->assertJsonPath('data.0.category', 'apara')->assertJsonPath('data.0.is_prescription', false);

        // Un code-barres inconnu : aucun résultat, sans erreur (la fenêtre ouvre la saisie à la main)
        $this->getJson('/api/v1/catalogue/produits?q=4006381333931', $this->enTetes($this->jeton))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalogue/produits?q=a', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    /** L'article : la famille « Accessoires et jouets », l'unité de vente, le fournisseur, le code-barres. */
    public function test_la_famille_accessoires_et_jouets_et_l_unite_de_vente(): void
    {
        $this->creer(['nom' => 'Balle qui couine', 'categorie' => 'acc', 'unite' => 'pièce', 'fournisseur' => 'Zoo+', 'ean' => '4006381333931'])
            ->assertCreated()
            ->assertJsonPath('data.categorie', 'acc')
            ->assertJsonPath('data.unite', 'pièce')
            ->assertJsonPath('data.fournisseur', 'Zoo+')
            ->assertJsonPath('data.ean', '4006381333931');
    }

    /** En stock ici : quantité, lot et péremption du stock du jour (plus de péremption sur l'article), dans la clinique ouverte. */
    public function test_le_stock_du_jour_a_son_lot_et_sa_peremption_dans_la_clinique_ouverte(): void
    {
        $id = $this->creer(['nom' => 'Metacam 100 ml', 'categorie' => 'med', 'quantite_initiale' => 6, 'lot' => 'A123B', 'lot_peremption' => '2027-05', 'seuil_alerte' => 2])
            ->assertCreated()
            ->assertJsonPath('data.peremption', null)
            ->assertJsonPath('data.seuil_alerte', 2)
            ->json('data.id');

        $lot = Lot::query()->withoutGlobalScopes()->where('produit_id', $id)->sole();
        $this->assertSame('A123B', $lot->numero_lot);
        $this->assertSame('2027-05-01', $lot->peremption_le->toDateString());
        $this->assertSame(6, (int) $lot->quantite);
        $this->assertSame($this->clinique->id, (int) $lot->clinique_id);
        $this->assertNull(Produit::find($id)->peremption_le);

        // Sans numéro de lot : un numéro attribué ; sans péremption : pas de lot
        $sansNumero = $this->creer(['nom' => 'Allermyl', 'categorie' => 'soin', 'quantite_initiale' => 2, 'lot_peremption' => '2027-01'])->json('data.id');
        $this->assertNotSame('', Lot::query()->withoutGlobalScopes()->where('produit_id', $sansNumero)->sole()->numero_lot);
        $sansPeremption = $this->creer(['nom' => 'Laisse', 'categorie' => 'acc', 'quantite_initiale' => 3])->json('data.id');
        $this->assertSame(0, Lot::query()->withoutGlobalScopes()->where('produit_id', $sansPeremption)->count());

        $this->creer(['nom' => 'X', 'categorie' => 'med', 'quantite_initiale' => 1, 'lot_peremption' => '13.2027'])->assertUnprocessable()->assertJsonValidationErrors('lot_peremption');
    }

    /** Vente : vendu en boutique, et alors promotion, mis en avant, photo pour la vitrine. */
    public function test_vendu_en_boutique_avec_promotion_mis_en_avant_et_photo(): void
    {
        $id = $this->creer(['nom' => 'Croquettes', 'categorie' => 'ali', 'est_boutique' => true, 'promo_pct' => 10, 'est_vedette' => true])
            ->assertCreated()
            ->assertJsonPath('data.est_boutique', true)
            ->assertJsonPath('data.promo_pct', 10)
            ->assertJsonPath('data.est_vedette', true)
            ->assertJsonPath('data.photo_url', null)
            ->json('data.id');

        Storage::fake('prive');
        $url = $this->post('/api/v1/stocks/produits/'.$id.'/photo', ['photo' => UploadedFile::fake()->image('croquettes.jpg', 600, 600)], $this->enTetes($this->jeton))
            ->assertOk()->json('data.photo_url');
        $this->assertNotNull($url);
        $this->get($url)->assertOk();

        $this->deleteJson('/api/v1/stocks/produits/'.$id.'/photo', [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.photo_url', null);

        // Hors boutique, ni promotion ni mise en avant
        $this->creer(['nom' => 'Gants', 'categorie' => 'mat', 'est_boutique' => false, 'promo_pct' => 10, 'est_vedette' => true])
            ->assertCreated()->assertJsonPath('data.promo_pct', 0)->assertJsonPath('data.est_vedette', false);
        $this->creer(['nom' => 'Gants', 'categorie' => 'mat', 'est_boutique' => true, 'promo_pct' => 120])->assertUnprocessable()->assertJsonValidationErrors('promo_pct');
    }

    /** Soins : ordonnance ; stupéfiant et son tableau ; code ATC et contenance pour les médicaments. */
    public function test_les_lignes_de_soins_d_un_medicament(): void
    {
        $this->creer([
            'nom' => 'Méthadone 10 mg/ml', 'categorie' => 'med', 'est_sur_ordonnance' => true,
            'est_stupefiant' => true, 'tableau_stupefiant' => 'a',
            'atc_code' => 'QN07BC02', 'content_per_unit' => 20, 'dose_unit' => 'ml',
        ])->assertCreated()
            ->assertJsonPath('data.est_sur_ordonnance', true)
            ->assertJsonPath('data.est_stupefiant', true)
            ->assertJsonPath('data.tableau_stupefiant', 'a')
            ->assertJsonPath('data.atc_code', 'QN07BC02')
            ->assertJsonPath('data.content_per_unit', 20)
            ->assertJsonPath('data.dose_unit', 'ml');

        // Un stupéfiant demande son tableau, et n'entre au stock que par son registre
        $this->creer(['nom' => 'Midazolam', 'categorie' => 'med', 'est_stupefiant' => true])->assertUnprocessable()->assertJsonValidationErrors('tableau_stupefiant');
        $this->creer(['nom' => 'Midazolam', 'categorie' => 'med', 'est_stupefiant' => true, 'tableau_stupefiant' => 'b', 'quantite_initiale' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('quantite_initiale');
        // La contenance demande son unité
        $this->creer(['nom' => 'Cerenia', 'categorie' => 'med', 'content_per_unit' => 20])->assertUnprocessable()->assertJsonValidationErrors('dose_unit');

        // Hors médicaments : ni ordonnance, ni stupéfiant, ni ATC, ni contenance
        $this->creer(['nom' => 'Balle', 'categorie' => 'acc', 'est_sur_ordonnance' => true, 'est_stupefiant' => true, 'tableau_stupefiant' => 'a', 'atc_code' => 'QM01AC06', 'content_per_unit' => 3, 'dose_unit' => 'ml'])
            ->assertCreated()
            ->assertJsonPath('data.est_sur_ordonnance', false)
            ->assertJsonPath('data.est_stupefiant', false)
            ->assertJsonPath('data.atc_code', null)
            ->assertJsonPath('data.content_per_unit', null);
        // Un antiparasitaire peut être sur ordonnance
        $this->creer(['nom' => 'Bravecto', 'categorie' => 'apara', 'est_sur_ordonnance' => true])->assertCreated()->assertJsonPath('data.est_sur_ordonnance', true);
    }

    public function test_un_stupefiant_ne_se_cree_qu_avec_le_droit_des_stupefiants(): void
    {
        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');

        $this->postJson('/api/v1/stocks/produits', ['nom' => 'Méthadone', 'categorie' => 'med', 'prix' => 10, 'est_stupefiant' => true, 'tableau_stupefiant' => 'a'], $this->enTetes($asv))
            ->assertForbidden();
    }

    /** « Commander automatiquement sous le seuil », avec ses sources, dès la création. */
    public function test_la_commande_automatique_et_ses_sources_des_la_creation(): void
    {
        $vetpoint = Supplier::query()->create(['clinique_id' => $this->clinique->id, 'name' => 'Vetpoint', 'channel' => 'manual']);

        $this->creer([
            'nom' => 'Metacam 100 ml', 'categorie' => 'med', 'seuil_alerte' => 3,
            'is_auto_order' => true, 'reorder_up_to' => 6,
            'sources' => [['supplier_id' => $vetpoint->id, 'reference' => '54321-01', 'cost' => 24.6]],
        ])->assertCreated()
            ->assertJsonPath('data.is_auto_order', true)
            ->assertJsonPath('data.reorder_up_to', 6)
            ->assertJsonPath('data.sources.0.supplier_name', 'Vetpoint')
            ->assertJsonPath('data.sources.0.cost', 24.6);

        // Remonter jusqu'à moins que le seuil n'a pas de sens
        $this->creer(['nom' => 'X', 'categorie' => 'med', 'seuil_alerte' => 3, 'is_auto_order' => true, 'reorder_up_to' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('reorder_up_to');
    }
}
