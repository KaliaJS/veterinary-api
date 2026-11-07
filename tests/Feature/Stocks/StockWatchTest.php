<?php

namespace Tests\Feature\Stocks;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Lot;
use App\Models\Produit;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * « À surveiller » des Stocks (vérification du 04.11.2026) : une seule règle,
 * servie. Pour chaque article, par clinique et toutes cliniques : la plus
 * proche péremption de ses lots en stock, son état (proche à moins de
 * 3 mois, périmé), et le stock bas ou la rupture ; puis les comptes.
 */
class StockWatchTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create();
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function article(string $nom, int $seuil, array $stocks, array $lots = []): Produit
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => $nom, 'seuil_alerte' => $seuil]);
        foreach ($stocks as $cliniqueId => $quantite) {
            Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $cliniqueId, 'quantite' => $quantite]);
        }
        foreach ($lots as [$cliniqueId, $peremption, $quantite]) {
            Lot::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $cliniqueId, 'peremption_le' => $peremption, 'quantite' => $quantite]);
        }

        return $produit;
    }

    public function test_la_plus_proche_peremption_son_etat_et_les_comptes_par_clinique_et_au_total(): void
    {
        [$ici, $soeur] = [$this->clinique->id, $this->soeur->id];
        // Proche ici (janvier 2027 : 2 mois), lointain chez la sœur
        $cerenia = $this->article('Cerenia', 5, [$ici => 3, $soeur => 10], [[$ici, '2027-01-01', 3], [$soeur, '2028-01-01', 10], [$ici, '2026-12-01', 0]]);
        // Périmé chez la sœur seulement
        $metacam = $this->article('Metacam', 0, [$ici => 12, $soeur => 2], [[$ici, '2027-09-01', 12], [$soeur, '2026-10-01', 2]]);
        // En rupture ici, sans seuil ; à 3 mois pile (février 2027) : pas encore proche
        $bravecto = $this->article('Bravecto', 0, [$ici => 0, $soeur => 4], [[$soeur, '2027-02-01', 4]]);

        $reponse = $this->getJson('/api/v1/stocks/surveillance', $this->enTetes($this->jeton))->assertOk();
        $de = fn (Produit $p) => collect($reponse->json('data.produits'))->firstWhere('id', $p->id);

        $this->assertSame(['date' => '01.2027', 'etat' => 'proche'], $de($cerenia)['peremption'][(string) $ici]);
        $this->assertSame(['date' => '01.2028', 'etat' => null], $de($cerenia)['peremption'][(string) $soeur]);
        $this->assertSame(['date' => '01.2027', 'etat' => 'proche'], $de($cerenia)['peremption']['toutes']);
        $this->assertSame(['date' => '10.2026', 'etat' => 'perime'], $de($metacam)['peremption'][(string) $soeur]);
        $this->assertSame(['date' => '10.2026', 'etat' => 'perime'], $de($metacam)['peremption']['toutes']);
        $this->assertSame(['date' => '02.2027', 'etat' => null], $de($bravecto)['peremption']['toutes']);
        $this->assertNull($de($bravecto)['peremption'][(string) $ici]);

        // Stock bas (seuil > 0, comme estSousLeSeuilDans) ou rupture
        $this->assertTrue($de($cerenia)['stock_bas'][(string) $ici]);
        $this->assertFalse($de($cerenia)['stock_bas'][(string) $soeur]);
        $this->assertFalse($de($metacam)['stock_bas'][(string) $soeur]);
        $this->assertTrue($de($bravecto)['stock_bas'][(string) $ici]);
        $this->assertFalse($de($bravecto)['stock_bas']['toutes']);

        $reponse->assertJsonPath("data.a_surveiller.{$ici}", ['stock_bas' => 2, 'peremption_3_mois' => 1])
            ->assertJsonPath("data.a_surveiller.{$soeur}", ['stock_bas' => 0, 'peremption_3_mois' => 1])
            ->assertJsonPath('data.a_surveiller.toutes', ['stock_bas' => 0, 'peremption_3_mois' => 2]);
    }

    public function test_une_autre_organisation_reste_invisible(): void
    {
        $etrangere = Clinique::factory()->create();
        $leur = Produit::factory()->create(['organisation_id' => $etrangere->societe->organisation_id, 'seuil_alerte' => 5]);
        Stock::factory()->create(['produit_id' => $leur->id, 'clinique_id' => $etrangere->id, 'quantite' => 1]);
        Lot::factory()->create(['produit_id' => $leur->id, 'clinique_id' => $etrangere->id, 'peremption_le' => '2026-12-01', 'quantite' => 1]);
        $this->article('Le nôtre', 0, [$this->clinique->id => 5], [[$this->clinique->id, '2028-01-01', 5]]);

        $reponse = $this->getJson('/api/v1/stocks/surveillance', $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(['Le nôtre'], collect($reponse->json('data.produits'))->map(fn ($p) => Produit::withoutGlobalScopes()->find($p['id'])->nom)->all());
        $this->assertArrayNotHasKey((string) $etrangere->id, $reponse->json('data.a_surveiller'));
        $reponse->assertJsonPath('data.a_surveiller.toutes', ['stock_bas' => 0, 'peremption_3_mois' => 0]);
    }

    public function test_sans_le_droit_des_stocks_rien(): void
    {
        ['jeton' => $sansDroit, 'employe' => $employe] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Stocks, 'est_accordee' => false]);
        $this->getJson('/api/v1/stocks/surveillance', $this->enTetes($sansDroit))->assertForbidden();
    }

    /** Un article sans aucune ligne de stock est en rupture, comme le dit sa pastille (vérification du 04.11.2026) */
    public function test_un_article_sans_ligne_de_stock_est_en_rupture(): void
    {
        $vide = $this->article('Jamais reçu', 0, []);
        $reponse = $this->getJson('/api/v1/stocks/surveillance', $this->enTetes($this->jeton))->assertOk();
        $ligne = collect($reponse->json('data.produits'))->firstWhere('id', $vide->id);
        $this->assertTrue($ligne['stock_bas']['toutes']);
        $this->assertFalse($ligne['stock_bas'][(string) $this->clinique->id]);
        $reponse->assertJsonPath('data.a_surveiller.toutes.stock_bas', 1);
    }

    /**
     * Les lots se lisent tels qu'ils sont (04.11.2026) : chaque sortie les
     * décompte (FEFO), la règle de secours qui supposait vendus les plus
     * anciens est retirée. Un lot vide ne compte pas ; un lot périmé encore
     * en rayon est dit périmé, même quand d'autres lots couvrent le stock.
     */
    public function test_les_lots_se_lisent_tels_qu_ils_sont_sans_regle_de_secours(): void
    {
        $ici = $this->clinique->id;
        // Un lot périmé encore noté, à côté d'un lot lointain qui couvre le stock : la règle de secours
        // le supposait vendu et le taisait ; il est dit
        $avecPerime = $this->article('Périmé encore en rayon', 0, [$ici => 3], [[$ici, '2028-01-01', 3], [$ici, '2026-10-01', 2]]);
        // Le lot périmé vidé par les sorties : il ne compte plus
        $vide = $this->article('Périmé vendu', 0, [$ici => 4], [[$ici, '2028-01-01', 4], [$ici, '2026-10-01', 0]]);

        $reponse = $this->getJson('/api/v1/stocks/surveillance', $this->enTetes($this->jeton))->assertOk();
        $de = fn (Produit $p) => collect($reponse->json('data.produits'))->firstWhere('id', $p->id);
        $this->assertSame(['date' => '10.2026', 'etat' => 'perime'], $de($avecPerime)['peremption'][(string) $ici]);
        $this->assertSame(['date' => '10.2026', 'etat' => 'perime'], $de($avecPerime)['peremption']['toutes']);
        $this->assertSame(['date' => '01.2028', 'etat' => null], $de($vide)['peremption'][(string) $ici]);
        $reponse->assertJsonPath("data.a_surveiller.{$ici}.peremption_3_mois", 1);
    }

    /**
     * Un lot sans péremption (matériel, accessoires : migration 000213) ne
     * fait pas tomber la surveillance (vérification du 04.11.2026) : il ne
     * périme jamais, il compte comme le plus lointain et n'a pas de date.
     */
    public function test_un_lot_sans_peremption_ne_fait_pas_tomber_la_surveillance(): void
    {
        $ici = $this->clinique->id;
        // Un lot sans péremption à côté d'un lot de 2027-01 : la date est celle du lot daté
        $mixte = $this->article('Laisse et vaccin', 0, [$ici => 6], [[$ici, null, 4], [$ici, '2027-01-01', 5]]);
        // Seulement du matériel sans péremption
        $materiel = $this->article('Muselière', 0, [$ici => 3], [[$ici, null, 3]]);

        $reponse = $this->getJson('/api/v1/stocks/surveillance', $this->enTetes($this->jeton))->assertOk();
        $de = fn (Produit $p) => collect($reponse->json('data.produits'))->firstWhere('id', $p->id);
        $this->assertSame(['date' => '01.2027', 'etat' => 'proche'], $de($mixte)['peremption'][(string) $ici]);
        $this->assertNull($de($materiel)['peremption'][(string) $ici]);
        $this->assertNull($de($materiel)['peremption']['toutes']);
    }
}
