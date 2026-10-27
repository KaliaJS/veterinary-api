<?php

namespace Tests\Feature\Stocks;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\ConnexionVetpoint;
use App\Models\DerogationPermission;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Vetpoint, étapes 1 et 2 (décision de Jim, 27.10.2026) : la clé d'API de
 * la clinique (vérifiée, chiffrée, jamais renvoyée) et la recherche de
 * produits au prix de la clinique, reliés au catalogue. Réponses simulées
 * d'après les vraies réponses du serveur de test du 27.10.2026 :
 * `{"products": [...]}` ou `{}`, `{"suppliers": [...]}`, prix par
 * `calculatePrices`.
 */
class VetpointTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string URL = 'https://vetpoint.test/api/tapsw';

    private const string CLE = 'CBFGLESURB4CLETESTVETPOINTAB12';

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vetpoint.url' => self::URL, 'services.vetpoint.profil' => 'https://vetpoint.test/ecommerce/control/profile']);
        $this->clinique = Clinique::factory()->create();
    }

    /** @return array<string, string> */
    private function admin(?Clinique $clinique = null): array
    {
        return $this->enTetes($this->administrateurAvecJeton($clinique ?? $this->clinique)['jeton']);
    }

    private function relier(?Clinique $clinique = null): void
    {
        ConnexionVetpoint::query()->create(['clinique_id' => ($clinique ?? $this->clinique)->id, 'cle' => self::CLE, 'verifiee_le' => now()]);
    }

    private function fauxVetpoint(array $produits = []): void
    {
        Http::fake([
            self::URL.'/v1/supplier' => Http::response(['suppliers' => [
                ['supplierId' => '913', 'name' => 'Provet AG', 'porto' => 9.4, 'portofreigrenze' => 250],
                ['supplierId' => '123', 'name' => 'Zoetis Schweiz GmbH', 'porto' => 0, 'portofreigrenze' => 0],
            ]]),
            self::URL.'/v1/product/query=*' => Http::response($produits === [] ? [] : ['products' => $produits]),
            self::URL.'/v1/product/swissmedic=*' => Http::response($produits === [] ? [] : ['products' => $produits]),
            self::URL.'/v1/product/calculatePrices' => fn (Request $requete) => Http::response(array_map(
                fn (array $ligne): array => ['productId' => $ligne['productId'], 'quantity' => $ligne['quantity'], 'totalPrice' => $ligne['productId'] === '913-306334' ? 44.65 : 120.0, 'status' => 'OK'],
                $requete->data(),
            )),
        ]);
    }

    /** @return array<string, mixed> */
    private function produitVetpoint(string $id = '913-306334', array $plus = []): array
    {
        return [
            'productId' => $id,
            'name' => 'Inflacam 1.5mg OS Hund 100ml',
            'listPrice' => 46.9,
            'swissmedicNumber' => '062533-01-003',
            'expirationDate' => '2027-06-30',
            'available' => true,
            'currency' => 'CHF',
            'supplierId' => '913',
            'abgabekategorie' => 'B',
            'einheitVerkauf' => 'ml',
            'anzahl' => 100,
            'betaeubungsmittel' => false,
            'barcode' => '7680625330039',
            ...$plus,
        ];
    }

    // ---------- Clé de la clinique ----------

    public function test_sans_cle_l_ecran_dit_comment_la_generer(): void
    {
        $this->getJson('/api/v1/vetpoint/connexion', $this->admin())
            ->assertOk()
            ->assertJsonPath('data.connectee', false)
            ->assertJsonPath('data.profil_url', 'https://vetpoint.test/ecommerce/control/profile');
    }

    public function test_la_cle_est_verifiee_puis_gardee_chiffree_pour_cette_clinique(): void
    {
        $this->fauxVetpoint();

        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => ' '.self::CLE.' '], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.connectee', true)
            ->assertJsonPath('data.cle_fin', 'AB12');

        Http::assertSent(fn (Request $requete): bool => $requete->url() === self::URL.'/v1/supplier' && $requete->header('X-Api-Key') === [self::CLE]);
        $connexion = ConnexionVetpoint::query()->sole();
        $this->assertSame($this->clinique->id, $connexion->clinique_id);
        $this->assertSame(self::CLE, $connexion->cle);
        $this->assertNotNull($connexion->verifiee_le);
        $brut = (string) DB::table('connexions_vetpoint')->value('cle');
        $this->assertStringNotContainsString(self::CLE, $brut);
    }

    public function test_une_cle_refusee_est_dite_et_rien_n_est_garde(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['statusCode' => 401, 'errorMessage' => 'Invalid API key'], 401)]);

        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $this->admin())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cle' => 'Vetpoint refuse cette clé : vérifiez-la ou générez-en une nouvelle dans votre profil Vetpoint.']);

        $this->assertSame(0, ConnexionVetpoint::query()->count());
    }

    public function test_vetpoint_injoignable_a_la_verification_rien_n_est_garde(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $this->admin())
            ->assertStatus(503)
            ->assertJsonPath('message', 'Vetpoint ne répond pas : réessayez dans un moment.');

        $this->assertSame(0, ConnexionVetpoint::query()->count());
    }

    public function test_la_cle_n_est_jamais_renvoyee(): void
    {
        $this->fauxVetpoint();
        $this->relier();

        $lecture = $this->getJson('/api/v1/vetpoint/connexion', $this->admin())->assertOk();
        $ecriture = $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $this->admin())->assertOk();

        $this->assertStringNotContainsString(self::CLE, $lecture->getContent());
        $this->assertStringNotContainsString(self::CLE, $ecriture->getContent());
        $lecture->assertJsonPath('data.cle_fin', 'AB12')->assertJsonPath('data.connectee', true);
        $this->assertNotNull($lecture->json('data.verifiee_le'));
    }

    public function test_la_cle_se_retire(): void
    {
        $this->relier();

        $this->deleteJson('/api/v1/vetpoint/connexion', [], $this->admin())->assertNoContent();

        $this->assertSame(0, ConnexionVetpoint::query()->count());
    }

    public function test_seul_le_droit_organisation_gere_la_cle(): void
    {
        $this->fauxVetpoint();
        $this->relier();
        ['employe' => $employe, 'jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Organisation, 'est_accordee' => false]);
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/vetpoint/connexion', $entetes)->assertForbidden();
        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => 'AUTRECLE'], $entetes)->assertForbidden();
        $this->deleteJson('/api/v1/vetpoint/connexion', [], $entetes)->assertForbidden();
        $this->assertSame(self::CLE, ConnexionVetpoint::query()->sole()->cle);
    }

    // ---------- Recherche ----------

    public function test_la_recherche_par_nom_montre_les_produits_au_prix_de_la_clinique(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint(), $this->produitVetpoint('123-10011090', ['name' => 'CONTACERA 20MG/ML INJ. 100ML', 'supplierId' => '123', 'available' => false, 'listPrice' => 124.96, 'swissmedicNumber' => '063245-01-002', 'anzahl' => 0, 'einheitVerkauf' => null])]);
        $this->relier();

        $reponse = $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $this->admin())->assertOk();

        Http::assertSent(fn (Request $requete): bool => $requete->url() === self::URL.'/v1/product/query=meloxicam');
        $reponse->assertJsonPath('data.0.id', '913-306334')
            ->assertJsonPath('data.0.nom', 'Inflacam 1.5mg OS Hund 100ml')
            ->assertJsonPath('data.0.swissmedic', '062533-01-003')
            ->assertJsonPath('data.0.fournisseur', 'Provet AG')
            ->assertJsonPath('data.0.disponible', true)
            ->assertJsonPath('data.0.peremption', '2027-06-30')
            ->assertJsonPath('data.0.categorie_remise', 'B')
            ->assertJsonPath('data.0.prix_catalogue', 46.9)
            ->assertJsonPath('data.0.prix_clinique', 44.65)
            ->assertJsonPath('data.1.fournisseur', 'Zoetis Schweiz GmbH')
            ->assertJsonPath('data.0.contenu', '100 ml')
            ->assertJsonPath('data.1.disponible', false)
            ->assertJsonPath('data.1.contenu', null)
            ->assertJsonPath('total', 2);
    }

    public function test_la_recherche_par_numero_swissmedic(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $this->relier();

        $this->getJson('/api/v1/vetpoint/produits?q=062533-01-003', $this->admin())->assertOk()->assertJsonPath('data.0.id', '913-306334');

        Http::assertSent(fn (Request $requete): bool => $requete->url() === self::URL.'/v1/product/swissmedic=062533-01-003');
    }

    public function test_une_recherche_sans_resultat(): void
    {
        $this->fauxVetpoint();
        $this->relier();

        $this->getJson('/api/v1/vetpoint/produits?q=metacam', $this->admin())->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 0);
    }

    public function test_au_dela_de_vingt_resultats_seuls_les_vingt_premiers_sont_montres(): void
    {
        $this->fauxVetpoint(array_map(fn (int $i): array => $this->produitVetpoint('913-'.$i), range(1, 31)));
        $this->relier();

        $reponse = $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $this->admin())->assertOk();

        $this->assertCount(20, $reponse->json('data'));
        $reponse->assertJsonPath('total', 31);
    }

    public function test_sans_cle_la_recherche_dit_d_aller_dans_les_reglages(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $this->admin())
            ->assertStatus(409)
            ->assertJsonPath('message', 'Vetpoint n’est pas relié à cette clinique : collez sa clé dans Réglages, Fournisseurs.');
        Http::assertNothingSent();
    }

    public function test_vetpoint_refuse_limite_ou_ne_repond_pas_un_message_clair(): void
    {
        $this->relier();
        $entetes = $this->admin();

        // Une seule réponse simulée, dont le statut change d'un appel à l'autre
        $statut = 401;
        Http::fake(function () use (&$statut) {
            if ($statut === 0) {
                throw new ConnectionException('timeout');
            }

            return Http::response([], $statut);
        });

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertStatus(409)
            ->assertJsonPath('message', 'Vetpoint refuse la clé de la clinique : elle a peut-être été renouvelée. Générez-en une et collez-la dans Réglages, Fournisseurs.');

        $statut = 429;
        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertStatus(429)
            ->assertJsonPath('message', 'Vetpoint limite les recherches : réessayez dans une minute.');

        $statut = 500;
        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertStatus(503)
            ->assertJsonPath('message', 'Vetpoint ne répond pas : réessayez dans un moment.');

        $statut = 0;
        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertStatus(503);
    }

    public function test_la_cle_d_une_clinique_ne_sert_pas_a_une_autre_clinique_de_l_organisation(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $this->relier();
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $this->admin($soeur))->assertStatus(409);
        $this->getJson('/api/v1/vetpoint/connexion', $this->admin($soeur))->assertOk()->assertJsonPath('data.connectee', false);
        Http::assertNothingSent();
    }

    // ---------- Lien avec le catalogue ----------

    public function test_un_produit_vetpoint_se_relie_au_catalogue_et_se_delie(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $this->relier();
        $entetes = $this->admin();
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Inflacam 100 ml']);

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/vetpoint", ['vetpoint_id' => '913-306334', 'nom' => 'Inflacam 1.5mg OS Hund 100ml', 'swissmedic' => '062533-01-003'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.vetpoint.id', '913-306334');

        // La fiche du produit et la recherche le montrent
        $this->getJson("/api/v1/stocks/produits/{$produit->id}", $entetes)
            ->assertJsonPath('data.vetpoint.id', '913-306334')
            ->assertJsonPath('data.vetpoint.nom', 'Inflacam 1.5mg OS Hund 100ml')
            ->assertJsonPath('data.vetpoint.swissmedic', '062533-01-003');
        $this->getJson('/api/v1/vetpoint/produits?q=inflacam', $entetes)
            ->assertJsonPath('data.0.produit.id', $produit->id)
            ->assertJsonPath('data.0.produit.nom', 'Inflacam 100 ml');

        $this->deleteJson("/api/v1/stocks/produits/{$produit->id}/vetpoint", [], $entetes)->assertOk()->assertJsonPath('data.vetpoint', null);
        $this->getJson("/api/v1/stocks/produits/{$produit->id}", $entetes)->assertJsonPath('data.vetpoint', null);
    }

    public function test_la_recherche_et_le_lien_demandent_le_droit_stocks(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $this->relier();
        ['employe' => $employe, 'jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Stocks, 'est_accordee' => false]);
        $entetes = $this->enTetes($jeton);
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertForbidden();
        $this->postJson("/api/v1/stocks/produits/{$produit->id}/vetpoint", ['vetpoint_id' => '913-306334', 'nom' => 'Inflacam'], $entetes)->assertForbidden();
        $this->deleteJson("/api/v1/stocks/produits/{$produit->id}/vetpoint", [], $entetes)->assertForbidden();
        Http::assertNothingSent();
    }
}
