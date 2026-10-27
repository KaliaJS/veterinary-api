<?php

namespace Tests\Feature\Stocks;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\ConnexionVetpoint;
use App\Models\DerogationPermission;
use App\Models\Produit;
use App\Models\ProduitVetpoint;
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

    // ---------- Vérification du 27.10.2026 ----------

    /** Une réponse de forme inattendue : « ne répond pas », jamais une erreur 500. */
    public function test_une_reponse_de_forme_inattendue_n_est_jamais_une_erreur_500(): void
    {
        $this->relier();
        $entetes = $this->admin();
        Http::fake([
            self::URL.'/v1/product/query=*' => Http::response(['products' => 'aucun']),
            self::URL.'/v1/supplier' => Http::response(['suppliers' => 'aucun']),
        ]);

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertStatus(503)
            ->assertJsonPath('message', 'Vetpoint ne répond pas : réessayez dans un moment.');
        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $entetes)->assertStatus(503);
    }

    /** Un numéro Swissmedic que Vetpoint ne connaît pas (404) : aucun résultat, pas une panne. */
    public function test_un_numero_swissmedic_inconnu_ne_donne_aucun_resultat(): void
    {
        $this->relier();
        Http::fake([self::URL.'/v1/product/swissmedic=*' => Http::response(['statusCode' => 404, 'errorMessage' => 'Product not found'], 404)]);

        $this->getJson('/api/v1/vetpoint/produits?q=999999-99-999', $this->admin())->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 0);
    }

    /** 403 de Vetpoint : les CGV de l'interface ne sont pas acceptées (OpenAPI), à la clé comme à la recherche. */
    public function test_des_cgv_non_acceptees_sont_dites(): void
    {
        $entetes = $this->admin();
        Http::fake([self::URL.'/*' => Http::response(['statusCode' => 403], 403)]);

        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $entetes)->assertUnprocessable()
            ->assertJsonValidationErrors(['cle' => 'Vetpoint refuse l’accès : cochez « J’accepte les CGV » dans votre profil Vetpoint, puis réessayez.']);
        $this->relier();
        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertStatus(409)
            ->assertJsonPath('message', 'Vetpoint refuse l’accès : cochez « J’accepte les CGV » dans le profil Vetpoint de la clinique, puis réessayez.');
    }

    /**
     * Vetpoint accepte 50 appels par minute et par clé ; une recherche en fait 2.
     * La clinique a 20 recherches par minute, toutes personnes confondues, avec
     * un message en français ; une autre clinique n'est pas touchée.
     */
    public function test_les_recherches_d_une_clinique_restent_sous_la_limite_de_vetpoint(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $this->relier();
        $marie = $this->admin();
        $paul = $this->admin();
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->relier($soeur);
        $entetesSoeur = $this->admin($soeur);

        foreach (range(1, 20) as $i) {
            $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $i % 2 ? $marie : $paul)->assertOk();
        }
        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $paul)->assertStatus(429)
            ->assertJsonPath('message', 'Trop de recherches Vetpoint pour la clinique : réessayez dans une minute.');
        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetesSoeur)->assertOk();

        $appels = collect(Http::recorded())->filter(fn ($paire) => str_contains($paire[0]->url(), '/v1/supplier'))->count();
        $this->assertSame(2, $appels, 'Fournisseurs lus une fois par clinique, puis gardés');
    }

    /** La liste des fournisseurs lue pour vérifier la clé est gardée : la première recherche ne la relit pas. */
    public function test_les_fournisseurs_lus_a_la_verification_servent_a_la_recherche(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $entetes = $this->admin();

        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $entetes)->assertOk();
        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertOk()->assertJsonPath('data.0.fournisseur', 'Provet AG');

        $this->assertSame(1, collect(Http::recorded())->filter(fn ($paire) => str_contains($paire[0]->url(), '/v1/supplier'))->count());
    }

    /** Le prix de la clinique pour une pièce : un seul calcul, 20 produits, quantité 1. */
    public function test_le_prix_est_calcule_pour_une_piece_en_un_seul_appel_de_vingt(): void
    {
        $this->fauxVetpoint(array_map(fn (int $i): array => $this->produitVetpoint('913-'.$i), range(1, 31)));
        $this->relier();

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $this->admin())->assertOk();

        $calculs = collect(Http::recorded())->filter(fn ($paire) => str_contains($paire[0]->url(), 'calculatePrices'))->values();
        $this->assertCount(1, $calculs);
        $lignes = $calculs[0][0]->data();
        $this->assertCount(20, $lignes);
        $this->assertSame([1], array_values(array_unique(array_column($lignes, 'quantity'))));
    }

    /** La liste du stock (celle que lit la fiche article) porte le produit Vetpoint relié. */
    public function test_la_liste_du_stock_porte_le_produit_vetpoint_relie(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        ProduitVetpoint::query()->create(['produit_id' => $produit->id, 'vetpoint_id' => '913-306334', 'nom' => 'Inflacam', 'swissmedic' => null]);

        $liste = $this->getJson('/api/v1/stocks/produits?per_page=100', $this->admin())->assertOk()->json('data');

        $this->assertSame('913-306334', collect($liste)->firstWhere('id', $produit->id)['vetpoint']['id']);
    }

    /** Un produit d'une autre organisation : introuvable pour relier, délier ou dans la recherche. */
    public function test_un_produit_d_une_autre_organisation_ne_se_relie_pas(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $this->relier();
        $entetes = $this->admin();
        $ailleurs = Produit::factory()->create(['organisation_id' => Clinique::factory()->create()->societe->organisation_id, 'nom' => 'Inflacam ailleurs']);
        ProduitVetpoint::query()->create(['produit_id' => $ailleurs->id, 'vetpoint_id' => '913-306334', 'nom' => 'Inflacam', 'swissmedic' => null]);

        $this->postJson("/api/v1/stocks/produits/{$ailleurs->id}/vetpoint", ['vetpoint_id' => '913-1', 'nom' => 'X'], $entetes)->assertNotFound();
        $this->deleteJson("/api/v1/stocks/produits/{$ailleurs->id}/vetpoint", [], $entetes)->assertNotFound();
        $this->getJson('/api/v1/vetpoint/produits?q=inflacam', $entetes)->assertOk()->assertJsonPath('data.0.produit', null);
    }

    /** La clé ne part jamais avec le modèle sérialisé. */
    public function test_la_cle_n_est_pas_dans_le_modele_serialise(): void
    {
        $this->relier();

        $this->assertArrayNotHasKey('cle', ConnexionVetpoint::query()->sole()->toArray());
    }

    // ---------- Vérification suivante (27.10.2026) ----------

    /** La recherche et le calcul de prix partent avec la clé de la clinique du jeton, pas celle d'une autre. */
    public function test_la_recherche_et_le_prix_partent_avec_la_cle_de_sa_clinique(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ConnexionVetpoint::query()->create(['clinique_id' => $soeur->id, 'cle' => 'CLEDELASOEUR0000000000000ZZ99', 'verifiee_le' => now()]);
        $this->relier();

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $this->admin())->assertOk();

        $cles = collect(Http::recorded())->map(fn ($paire) => $paire[0]->header('X-Api-Key')[0] ?? null)->unique()->values()->all();
        $this->assertSame([self::CLE], $cles);
        Http::assertSent(fn (Request $requete): bool => str_contains($requete->url(), 'calculatePrices') && $requete->header('X-Api-Key') === [self::CLE]);
    }

    /** Seul un prix calculé (statut OK, nombre) compte ; « n/a » ou un refus de calcul : pas de prix. */
    public function test_seul_un_prix_calcule_est_montre(): void
    {
        $this->relier();
        Http::fake([
            self::URL.'/v1/supplier' => Http::response(['suppliers' => []]),
            self::URL.'/v1/product/query=*' => Http::response(['products' => [$this->produitVetpoint('913-1'), $this->produitVetpoint('913-2'), $this->produitVetpoint('913-3')]]),
            self::URL.'/v1/product/calculatePrices' => Http::response([
                ['productId' => '913-1', 'quantity' => 1, 'totalPrice' => 10.5, 'status' => 'OK'],
                ['productId' => '913-2', 'quantity' => 1, 'totalPrice' => 'n/a', 'status' => 'OK'],
                ['productId' => '913-3', 'quantity' => 1, 'totalPrice' => 12, 'status' => 'NOT_AVAILABLE'],
            ]),
        ]);

        $reponse = $this->getJson('/api/v1/vetpoint/produits?q=inflacam', $this->admin())->assertOk();

        $this->assertSame([10.5, null, null], array_column($reponse->json('data'), 'prix_clinique'));
    }

    /**
     * Le compteur de la clinique ne compte que les vraies recherches envoyées à
     * Vetpoint : un employé suspendu ou sans droit, une recherche trop courte
     * ne bloquent pas ses collègues.
     */
    public function test_les_requetes_refusees_ne_comptent_pas_dans_la_limite_de_la_clinique(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $this->relier();
        ['employe' => $sansDroit, 'jeton' => $jetonSansDroit] = $this->administrateurAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $sansDroit->id, 'domaine' => DomainePermission::Stocks, 'est_accordee' => false]);
        $collegue = $this->admin();

        foreach (range(1, 25) as $i) {
            $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $this->enTetes($jetonSansDroit))->assertForbidden();
            $this->getJson('/api/v1/vetpoint/produits?q=m', $collegue)->assertUnprocessable();
        }

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $collegue)->assertOk();
    }

    /** Un 404 sur une recherche par nom (adresse de Vetpoint fausse ou déplacée) : une panne, pas « aucun résultat ». */
    public function test_un_404_sur_une_recherche_par_nom_est_une_panne(): void
    {
        $this->relier();
        Http::fake([self::URL.'/*' => Http::response('<html>Not Found</html>', 404)]);

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $this->admin())->assertStatus(503);
    }

    /** Un nom ou un identifiant de forme inattendue (objet au lieu de texte) : le produit est ignoré, jamais une erreur. */
    public function test_un_produit_aux_champs_inattendus_est_ignore(): void
    {
        $this->relier();
        Http::fake([
            self::URL.'/v1/supplier' => Http::response(['suppliers' => []]),
            self::URL.'/v1/product/query=*' => Http::response(['products' => [
                $this->produitVetpoint('913-1', ['name' => ['de' => 'Inflacam']]),
                $this->produitVetpoint('913-2'),
                $this->produitVetpoint('913-3', ['productId' => ['913-3']]),
            ]]),
            self::URL.'/v1/product/calculatePrices' => Http::response([]),
        ]);

        $reponse = $this->getJson('/api/v1/vetpoint/produits?q=inflacam', $this->admin())->assertOk();

        $this->assertSame(['913-1', '913-2'], array_column($reponse->json('data'), 'id'));
        $reponse->assertJsonPath('data.0.nom', '');
    }

    /** Une clé avec un caractère invisible : refusée avec un message, jamais une erreur 500. */
    public function test_une_cle_avec_un_caractere_invisible_est_refusee(): void
    {
        Http::fake();

        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => 'ABCDEFGHIJKLMNOP'], $this->admin())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cle' => 'Cette clé contient des caractères inattendus : copiez-la à nouveau depuis votre profil Vetpoint.']);
        Http::assertNothingSent();
    }

    /** Trop d'essais de clé : un message en français. */
    public function test_trop_d_essais_de_cle_le_disent_en_francais(): void
    {
        Http::fake([self::URL.'/*' => Http::response([], 401)]);
        $entetes = $this->admin();

        foreach (range(1, 10) as $i) {
            $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $entetes)->assertUnprocessable();
        }
        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $entetes)->assertStatus(429)
            ->assertJsonPath('message', 'Trop d’essais de clé Vetpoint : réessayez dans une minute.');
    }

    /** Un fournisseur au nom de forme inattendue : ignoré, la clé s'enregistre et la recherche marche. */
    public function test_un_fournisseur_aux_champs_inattendus_est_ignore(): void
    {
        Http::fake([
            self::URL.'/v1/supplier' => Http::response(['suppliers' => [
                ['supplierId' => '913', 'name' => ['de' => 'Provet AG']],
                ['supplierId' => ['123'], 'name' => 'Zoetis'],
                ['supplierId' => '110', 'name' => 'Arovet AG'],
            ]]),
            self::URL.'/v1/product/query=*' => Http::response(['products' => [$this->produitVetpoint('913-1', ['supplierId' => '110'])]]),
            self::URL.'/v1/product/calculatePrices' => Http::response([]),
        ]);
        $entetes = $this->admin();

        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $entetes)->assertOk();
        $this->getJson('/api/v1/vetpoint/produits?q=inflacam', $entetes)->assertOk()->assertJsonPath('data.0.fournisseur', 'Arovet AG');
    }

    /** Un nombre démesuré (1e400) : pas de prix, jamais une erreur 500. */
    public function test_un_nombre_demesure_n_est_jamais_une_erreur_500(): void
    {
        $this->relier();
        Http::fake([
            self::URL.'/v1/supplier' => Http::response(['suppliers' => []]),
            self::URL.'/v1/product/query=*' => Http::response('{"products":[{"productId":"913-1","name":"X","listPrice":1e400}]}', 200, ['Content-Type' => 'application/json']),
            self::URL.'/v1/product/calculatePrices' => Http::response('[{"productId":"913-1","quantity":1,"totalPrice":"1e400","status":"OK"}]', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->getJson('/api/v1/vetpoint/produits?q=inflacam', $this->admin())->assertOk()
            ->assertJsonPath('data.0.prix_catalogue', null)
            ->assertJsonPath('data.0.prix_clinique', null);
    }

    /** Une clé devenue illisible (clé de chiffrement du serveur changée) : la recollez, jamais une erreur 500. */
    public function test_une_cle_illisible_demande_de_la_recoller(): void
    {
        $this->relier();
        DB::table('connexions_vetpoint')->update(['cle' => 'pas-un-chiffre-valable']);
        $entetes = $this->admin();

        $this->getJson('/api/v1/vetpoint/produits?q=inflacam', $entetes)->assertStatus(409)
            ->assertJsonPath('message', 'La clé Vetpoint de la clinique est illisible : collez-la à nouveau dans Réglages, Fournisseurs.');
        $this->getJson('/api/v1/vetpoint/connexion', $entetes)->assertOk()->assertJsonPath('data.connectee', true)->assertJsonPath('data.cle_fin', null);
    }

    /** Un 404 en page HTML sur une recherche Swissmedic (adresse fausse) : une panne, pas « aucun résultat ». */
    public function test_un_404_sans_produit_introuvable_est_une_panne(): void
    {
        $this->relier();
        Http::fake([self::URL.'/*' => Http::response('<html>Not Found</html>', 404)]);

        $this->getJson('/api/v1/vetpoint/produits?q=062533-01-003', $this->admin())->assertStatus(503);
    }

    /** Les requêtes sans clé ne consomment pas le compteur : la clé collée, la recherche marche aussitôt. */
    public function test_les_recherches_sans_cle_ne_comptent_pas(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $entetes = $this->admin();

        foreach (range(1, 25) as $i) {
            $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertStatus(409);
        }
        $this->relier();

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertOk();
    }

    /** Une nouvelle clé collée par-dessus une clé devenue illisible : elle la remplace, jamais une erreur 500. */
    public function test_une_nouvelle_cle_remplace_une_cle_illisible(): void
    {
        $this->fauxVetpoint();
        $this->relier();
        DB::table('connexions_vetpoint')->update(['cle' => 'pas-un-chiffre-valable']);

        $this->putJson('/api/v1/vetpoint/connexion', ['cle' => self::CLE], $this->admin())->assertOk()->assertJsonPath('data.cle_fin', 'AB12');

        $this->assertSame(self::CLE, ConnexionVetpoint::query()->sole()->cleEnClair());
    }

    /** Un 404 Swissmedic de forme inattendue (message en liste) ou générique (« Not Found ») : une panne. */
    public function test_seul_le_404_produit_introuvable_donne_aucun_resultat(): void
    {
        $this->relier();
        $entetes = $this->admin();
        $corps = ['errorMessage' => ['Product not found']];
        Http::fake(function () use (&$corps) {
            return Http::response($corps, 404);
        });

        $this->getJson('/api/v1/vetpoint/produits?q=062533-01-003', $entetes)->assertStatus(503);
        $corps = ['errorMessage' => 'Not Found'];
        $this->getJson('/api/v1/vetpoint/produits?q=062533-01-003', $entetes)->assertStatus(503);
        $corps = ['statusCode' => 404, 'errorMessage' => 'Product not found'];
        $this->getJson('/api/v1/vetpoint/produits?q=062533-01-003', $entetes)->assertOk()->assertJsonPath('data', []);
    }

    /** Une clé illisible ne consomme pas le compteur : recollée, la recherche marche aussitôt. */
    public function test_les_recherches_avec_une_cle_illisible_ne_comptent_pas(): void
    {
        $this->fauxVetpoint([$this->produitVetpoint()]);
        $this->relier();
        DB::table('connexions_vetpoint')->update(['cle' => 'pas-un-chiffre-valable']);
        $entetes = $this->admin();

        foreach (range(1, 25) as $i) {
            $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertStatus(409);
        }
        ConnexionVetpoint::query()->delete();
        $this->relier();

        $this->getJson('/api/v1/vetpoint/produits?q=meloxicam', $entetes)->assertOk();
    }

    /** Un contenu démesuré (1e400) : rien d'affiché, jamais « INF ml ». */
    public function test_un_contenu_demesure_n_est_pas_affiche(): void
    {
        $this->relier();
        Http::fake([
            self::URL.'/v1/supplier' => Http::response(['suppliers' => []]),
            self::URL.'/v1/product/query=*' => Http::response('{"products":[{"productId":"913-1","name":"X","anzahl":1e400,"einheitVerkauf":"ml"}]}', 200, ['Content-Type' => 'application/json']),
            self::URL.'/v1/product/calculatePrices' => Http::response([]),
        ]);

        $this->getJson('/api/v1/vetpoint/produits?q=inflacam', $this->admin())->assertOk()->assertJsonPath('data.0.contenu', null);
    }
}
