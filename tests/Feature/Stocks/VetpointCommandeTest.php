<?php

namespace Tests\Feature\Stocks;

use App\Enums\StatutCommandeFournisseur;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\ConnexionVetpoint;
use App\Models\Lot;
use App\Models\Produit;
use App\Models\ProduitVetpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Vetpoint, étapes 3 et 4 (demande de Jim, 28.10.2026) : une commande
 * fournisseur part par Vetpoint (adresse du compte de la clinique, standard
 * ou express, en une fois, jamais deux fois), puis se réceptionne avec les
 * prix réels et les péremptions que Vetpoint donne. Formes des réponses
 * lues sur le serveur de test : adresses en liste, commandes en liste (ou
 * `{}` sans dates).
 */
class VetpointCommandeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string URL = 'https://vetpoint.test/api/tapsw';

    private const string CLE = 'CBFGLESURB4CLETESTVETPOINTAB12';

    private Clinique $clinique;

    private array $entetes;

    /** @var list<array{methode: string, chemin: string, corps: array<mixed>}> */
    private array $appels = [];

    /** Commandes connues de Vetpoint (GET /v1/order) */
    private array $commandesVetpoint = [];

    private bool $envoiPerdu = false;

    private ?array $refus = null;

    private ?array $detail = null;

    /** Numéros des brouillons : le premier est BC-2026-042 */
    private int $prochainNumero = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00', 'Europe/Zurich'));
        config(['services.vetpoint.url' => self::URL]);
        $this->clinique = Clinique::factory()->create();
        $this->entetes = $this->enTetes($this->administrateurAvecJeton($this->clinique)['jeton']);
        ConnexionVetpoint::query()->create(['clinique_id' => $this->clinique->id, 'cle' => self::CLE, 'verifiee_le' => now()]);
        $this->fauxVetpoint();
    }

    private function fauxVetpoint(): void
    {
        Http::fake(function (Request $requete) {
            $chemin = (string) parse_url($requete->url(), PHP_URL_PATH);
            $chemin = substr($chemin, strlen('/api/tapsw'));
            $this->appels[] = ['methode' => $requete->method(), 'chemin' => $chemin, 'corps' => $requete->data()];

            if ($requete->header('X-Api-Key')[0] !== self::CLE) {
                return Http::response(['errorMessage' => 'Unauthorized'], 401);
            }
            if ($chemin === '/v1/shipping-address') {
                return Http::response([
                    ['shippingAddressId' => 'A1', 'company' => 'Clinique des Deux Rives', 'address1' => 'Rue du Lac 1', 'postalCode' => '1000', 'city' => 'Lausanne', 'defaultShippingAddress' => false],
                    ['shippingAddressId' => 'A2', 'company' => 'Clinique des Deux Rives', 'address1' => 'Chemin Vert 4', 'postalCode' => '1004', 'city' => 'Lausanne', 'defaultShippingAddress' => true],
                ]);
            }
            if ($chemin === '/v1/order' && $requete->method() === 'GET') {
                // Sans commande, Vetpoint répond `{}` (lu sur le serveur de test)
                return $this->commandesVetpoint === [] ? Http::response('{}', 200, ['Content-Type' => 'application/json']) : Http::response($this->commandesVetpoint);
            }
            if ($chemin === '/v1/order' && $requete->method() === 'POST') {
                if ($this->refus !== null) {
                    return Http::response($this->refus['corps'], $this->refus['statut']);
                }
                if ($this->envoiPerdu) {
                    // Vetpoint l'a reçue, mais la réponse se perd en route
                    $this->commandesVetpoint[] = ['orderId' => 'V-7001', 'orderDate' => now()->toIso8601String(), 'orderItems' => array_map(fn (array $ligne) => ['productId' => $ligne['productId'], 'quantity' => $ligne['quantity'], 'orderNote' => $ligne['orderNote'] ?? null], $requete->data()['orderItems'] ?? [])];
                    throw new ConnectionException('Délai dépassé');
                }

                return Http::response('V-7001');
            }
            if (str_starts_with($chemin, '/v1/order/')) {
                return $this->detail === null ? Http::response(['errorMessage' => 'Server Error'], 500) : Http::response($this->detail);
            }

            return Http::response(['errorMessage' => 'Not Found'], 404);
        });
    }

    /** Un brouillon de la clinique, avec des lignes de produits reliés (ou non) à Vetpoint. */
    private function brouillon(array $produits = ['913-306334' => 'Rimadyl 50 mg']): CommandeFournisseur
    {
        $organisation = $this->clinique->societe->organisation_id;
        $commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id, 'numero' => 'BC-2026-'.str_pad((string) $this->prochainNumero++, 3, '0', STR_PAD_LEFT)]);
        foreach ($produits as $vetpointId => $nom) {
            $produit = Produit::factory()->create(['organisation_id' => $organisation, 'nom' => $nom, 'cout' => 10, 'peremption_le' => '2027-01-01']);
            if (! str_starts_with((string) $vetpointId, 'sans-')) {
                ProduitVetpoint::query()->create(['produit_id' => $produit->id, 'vetpoint_id' => (string) $vetpointId, 'nom' => $nom]);
            }
            CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 4, 'cout' => 10]);
        }

        return $commande;
    }

    private function envoyer(CommandeFournisseur $commande, array $choix = ['adresse_id' => 'A2', 'livraison' => 'standard'])
    {
        return $this->postJson("/api/v1/stocks/commandes/{$commande->id}/vetpoint", $choix, $this->entetes);
    }

    /** Les envois de commande reçus par Vetpoint */
    private function envois(): array
    {
        return array_values(array_filter($this->appels, fn (array $appel) => $appel['methode'] === 'POST' && $appel['chemin'] === '/v1/order'));
    }

    /* ——— Envoi par Vetpoint ——— */

    public function test_une_commande_reliee_part_par_vetpoint_en_une_fois_et_garde_son_numero(): void
    {
        $commande = $this->brouillon(['913-306334' => 'Rimadyl 50 mg', '913-111111' => 'Metacam 1.5']);

        $this->envoyer($commande)
            ->assertOk()
            ->assertJsonPath('data.statut', 'sent')
            ->assertJsonPath('data.vetpoint.numero', 'V-7001');

        $this->assertCount(1, $this->envois());
        $corps = $this->envois()[0]['corps'];
        $this->assertSame('A2', $corps['shippingAddressId']);
        $this->assertSame('STANDARD', $corps['deliveryType']);
        $this->assertSame([
            ['productId' => '913-306334', 'quantity' => 4, 'orderNote' => 'Veterinary BC-2026-042'],
            ['productId' => '913-111111', 'quantity' => 4, 'orderNote' => 'Veterinary BC-2026-042'],
        ], $corps['orderItems']);
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $commande->refresh()->statut);
        $this->assertSame('V-7001', $commande->vetpoint_commande_id);
        $this->assertNotNull($commande->envoyee_le);
    }

    public function test_une_ligne_non_reliee_empeche_l_envoi_avec_le_nom_du_produit(): void
    {
        $commande = $this->brouillon(['913-306334' => 'Rimadyl 50 mg', 'sans-1' => 'Gants nitrile']);

        $this->envoyer($commande)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => 'Pas encore relié à Vetpoint : Gants nitrile. Reliez-le depuis le stock, ou envoyez la commande autrement.']);
        $this->assertSame([], $this->envois());
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $commande->refresh()->statut);
    }

    public function test_les_adresses_de_livraison_viennent_du_compte_vetpoint_celle_par_defaut_proposee(): void
    {
        $commande = $this->brouillon();

        $this->getJson("/api/v1/stocks/commandes/{$commande->id}/vetpoint/adresses", $this->entetes)
            ->assertOk()
            ->assertJsonPath('data.0.id', 'A1')
            ->assertJsonPath('data.0.par_defaut', false)
            ->assertJsonPath('data.1.id', 'A2')
            ->assertJsonPath('data.1.libelle', 'Clinique des Deux Rives, Chemin Vert 4, 1004 Lausanne')
            ->assertJsonPath('data.1.par_defaut', true);

        // Une adresse qui n'est pas du compte : refusée avant Vetpoint
        $this->envoyer($commande, ['adresse_id' => 'AUTRE', 'livraison' => 'standard'])
            ->assertUnprocessable()->assertJsonValidationErrors(['adresse_id' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.']);
        $this->assertSame([], $this->envois());
    }

    public function test_le_type_de_livraison_se_choisit_standard_ou_express(): void
    {
        $commande = $this->brouillon();

        $this->envoyer($commande, ['adresse_id' => 'A2', 'livraison' => 'avion'])->assertUnprocessable()->assertJsonValidationErrors('livraison');
        $this->envoyer($commande, ['adresse_id' => 'A2', 'livraison' => 'express'])->assertOk()->assertJsonPath('data.vetpoint.livraison', 'express');

        $this->assertSame('EXPRESS', $this->envois()[0]['corps']['deliveryType']);
    }

    public function test_une_date_souhaitee_en_standard_dans_le_futur_jamais_en_express(): void
    {
        $commande = $this->brouillon();

        $this->envoyer($commande, ['adresse_id' => 'A2', 'livraison' => 'standard', 'date_souhaitee' => '2026-10-28'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date_souhaitee' => 'La date de livraison souhaitée doit être dans le futur.']);
        $this->envoyer($commande, ['adresse_id' => 'A2', 'livraison' => 'express', 'date_souhaitee' => '2026-11-03'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date_souhaitee' => 'Pas de date souhaitée en express : la livraison est la plus rapide possible.']);
        $this->assertSame([], $this->envois());

        $this->envoyer($commande, ['adresse_id' => 'A2', 'livraison' => 'standard', 'date_souhaitee' => '2026-11-03'])
            ->assertOk()->assertJsonPath('data.vetpoint.date_souhaitee', '2026-11-03');
        $this->assertStringStartsWith('2026-11-03', $this->envois()[0]['corps']['desiredDeliveryDate']);
    }

    public function test_deux_envois_ne_creent_jamais_deux_commandes(): void
    {
        $commande = $this->brouillon();

        $this->envoyer($commande)->assertOk();
        $this->envoyer($commande)->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Seul un brouillon s’envoie.']);

        // Un envoi encore en cours (deuxième onglet) : refusé sans appeler Vetpoint
        $autre = $this->brouillon();
        $autre->forceFill(['vetpoint_envoi_le' => now()])->save();
        $this->envoyer($autre)->assertStatus(409)->assertJsonPath('message', 'Cette commande part déjà chez Vetpoint : patientez quelques secondes.');

        $this->assertCount(1, $this->envois());
    }

    public function test_une_reponse_perdue_se_retrouve_chez_vetpoint_au_lieu_d_etre_renvoyee(): void
    {
        $commande = $this->brouillon();
        $this->envoiPerdu = true;

        $this->envoyer($commande)->assertStatus(503)
            ->assertJsonPath('message', 'Vetpoint ne répond pas : la commande est peut-être partie. Réessayez dans une minute, elle ne partira pas deux fois.');
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $commande->refresh()->statut);

        // Une minute plus tard : retrouvée dans les commandes de Vetpoint, pas renvoyée
        $this->envoiPerdu = false;
        $this->travel(2)->minutes();
        $this->envoyer($commande)->assertOk()->assertJsonPath('data.vetpoint.numero', 'V-7001');

        $this->assertCount(1, $this->envois());
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $commande->refresh()->statut);
    }

    public function test_une_reponse_perdue_sans_commande_chez_vetpoint_repart_une_fois(): void
    {
        $commande = $this->brouillon();
        $commande->forceFill(['vetpoint_envoi_le' => now()->subMinutes(5)])->save();

        $this->envoyer($commande)->assertOk();

        $this->assertCount(1, $this->envois());
    }

    public function test_les_refus_de_vetpoint_s_affichent_en_francais(): void
    {
        $cas = [
            [422, ['errorTyoe' => '1122', 'errorMessage' => "Das Produkt 'Rimadyl 50 mg' hat eine Mindestbestellmenge von 10"], 'Le produit « Rimadyl 50 mg » se commande par 10 au moins.'],
            [422, ['errorTyoe' => '4003', 'errorMessage' => 'Individual shipping address cannot be entered'], 'Les médicaments ne se livrent qu’à une adresse enregistrée chez Vetpoint.'],
            [422, ['errorTyoe' => '4002', 'errorMessage' => 'Invalid shipping address provided'], 'Cette adresse de livraison n’est pas valable chez Vetpoint.'],
            [422, ['errorTyoe' => '1063', 'errorMessage' => 'The desired delivery date must be in the future'], 'La date de livraison souhaitée doit être dans le futur.'],
            [422, ['errorTyoe' => '5000', 'errorMessage' => 'not specified'], 'Vetpoint a refusé la commande sans dire pourquoi : contactez Vetpoint.'],
            [400, ['errorMessage' => 'Product not found'], 'Un produit de la commande est introuvable chez Vetpoint : vérifiez ses liens.'],
            [403, ['errorMessage' => 'Terms'], 'Vetpoint refuse l’accès : cochez « J’accepte les CGV » dans le profil Vetpoint de la clinique, puis réessayez.'],
            [401, ['errorMessage' => 'Unauthorized'], 'Vetpoint refuse la clé de la clinique : elle a peut-être été renouvelée. Générez-en une et collez-la dans Réglages, Fournisseurs.'],
        ];

        foreach ($cas as [$statut, $corps, $message]) {
            $commande = $this->brouillon();
            $this->refus = ['statut' => $statut, 'corps' => $corps];

            $reponse = $this->envoyer($commande);

            $this->assertContains($reponse->status(), [409, 422], $message);
            $this->assertSame($message, $reponse->json('errors.vetpoint.0') ?? $reponse->json('message'));
            $this->assertNull($commande->refresh()->vetpoint_envoi_le, 'Un refus net libère la commande');
            $this->assertSame(StatutCommandeFournisseur::Brouillon, $commande->statut);
        }
    }

    /* ——— Suivi et réception ——— */

    private function envoyee(): CommandeFournisseur
    {
        $commande = $this->brouillon(['913-306334' => 'Rimadyl 50 mg']);
        $this->envoyer($commande)->assertOk();
        $this->detail = ['orderId' => 'V-7001', 'orderDate' => '2026-10-28T10:00:00', 'deliveryType' => 'STANDARD', 'orderItems' => [
            ['productId' => '913-306334', 'supplierId' => 'S1', 'quantity' => 4, 'unitPrice' => 12.35, 'totalPrice' => 49.4, 'expirationDate' => '2027-05-31', 'orderNote' => 'Veterinary BC-2026-042'],
        ]];

        return $commande->refresh();
    }

    public function test_une_commande_envoyee_montre_son_numero_et_ses_lignes_avec_prix_reels_et_peremption(): void
    {
        $commande = $this->envoyee();
        $ligne = $commande->lignes()->first();

        $this->getJson("/api/v1/stocks/commandes/{$commande->id}/vetpoint", $this->entetes)
            ->assertOk()
            ->assertJsonPath('data.numero', 'V-7001')
            ->assertJsonPath('data.lignes.0.ligne_id', $ligne->id)
            ->assertJsonPath('data.lignes.0.prix_unitaire', 12.35)
            ->assertJsonPath('data.lignes.0.prix_total', 49.4)
            ->assertJsonPath('data.lignes.0.peremption', '2027-05-31');
    }

    public function test_a_la_reception_chaque_ligne_prend_le_prix_unitaire_reel(): void
    {
        $commande = $this->envoyee();

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)->assertOk()->assertJsonPath('data.reception_vetpoint.lu', true);

        $this->assertSame('12.35', (string) $commande->lignes()->first()->cout);
    }

    public function test_a_la_reception_le_cout_du_produit_prend_le_prix_reel(): void
    {
        $commande = $this->envoyee();

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)->assertOk();

        $this->assertSame('12.35', (string) $commande->lignes()->first()->produit->refresh()->cout);
    }

    public function test_a_la_reception_chaque_lot_prend_la_peremption_de_vetpoint(): void
    {
        $commande = $this->envoyee();
        $produit = $commande->lignes()->first()->produit;

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)->assertOk();

        // Lots : le premier jour du mois de péremption
        $this->assertSame('2027-05-01', Lot::query()->where('produit_id', $produit->id)->sole()->peremption_le->toDateString());
    }

    public function test_vetpoint_injoignable_a_la_reception_le_stock_entre_quand_meme(): void
    {
        $commande = $this->envoyee();
        $this->detail = null;
        $produit = $commande->lignes()->first()->produit;

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'rec')
            ->assertJsonPath('data.reception_vetpoint.lu', false)
            ->assertJsonPath('data.reception_vetpoint.message', 'Vetpoint ne répond pas : le stock est entré avec les prix de la commande, sans les prix réels ni les péremptions de Vetpoint.');

        $this->assertSame('10.00', (string) $commande->lignes()->first()->cout);
        $this->assertSame('2027-01-01', Lot::query()->where('produit_id', $produit->id)->sole()->peremption_le->toDateString());
    }
}
