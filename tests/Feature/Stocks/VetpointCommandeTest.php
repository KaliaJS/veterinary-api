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

    private const string CLE_SOEUR = 'SOEURCLETESTVETPOINTAB1234567';

    private Clinique $clinique;

    private array $entetes;

    /** @var list<array{methode: string, chemin: string, corps: array<mixed>}> */
    private array $appels = [];

    /** Commandes connues de Vetpoint (GET /v1/order) */
    private array $commandesVetpoint = [];

    private bool $envoiPerdu = false;

    private ?array $refus = null;

    private ?array $detail = null;

    private ?int $rechercheRefusee = null;

    /** Vetpoint ne rend pas la note interne des lignes dans GET /v1/order */
    private bool $sansNote = false;

    /** Corps JSON de la réponse 200 à un envoi (forme inattendue) */
    private ?string $reponseEnvoi = null;

    private bool $panneAvantVetpoint = false;

    /** Forme de la liste des commandes rendue par Vetpoint (corps JSON) */
    private ?\Closure $listeCommandes = null;

    /** Un autre onglet agit pendant que Vetpoint lit les adresses */
    private ?\Closure $pendantLesAdresses = null;

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
            $this->appels[] = ['methode' => $requete->method(), 'chemin' => $chemin, 'corps' => $requete->data(), 'cle' => $requete->header('X-Api-Key')[0]];

            if (! in_array($requete->header('X-Api-Key')[0], [self::CLE, self::CLE_SOEUR], true)) {
                return Http::response(['errorMessage' => 'Unauthorized'], 401);
            }
            if ($chemin === '/v1/shipping-address') {
                if ($this->pendantLesAdresses !== null) {
                    ($this->pendantLesAdresses)();
                    $this->pendantLesAdresses = null;
                }

                return Http::response([
                    ['shippingAddressId' => 'A1', 'company' => 'Clinique des Deux Rives', 'address1' => 'Rue du Lac 1', 'postalCode' => '1000', 'city' => 'Lausanne', 'defaultShippingAddress' => false],
                    ['shippingAddressId' => 'A2', 'company' => 'Clinique des Deux Rives', 'address1' => 'Chemin Vert 4', 'postalCode' => '1004', 'city' => 'Lausanne', 'defaultShippingAddress' => true],
                ]);
            }
            if ($chemin === '/v1/order' && $requete->method() === 'GET' && $this->rechercheRefusee !== null) {
                return Http::response(['errorMessage' => 'refus'], $this->rechercheRefusee);
            }
            if ($chemin === '/v1/order' && $requete->method() === 'GET') {
                // Sans commande, Vetpoint répond `{}` (lu sur le serveur de test)
                if ($this->listeCommandes !== null) {
                    return Http::response(($this->listeCommandes)($this->commandesVetpoint), 200, ['Content-Type' => 'application/json']);
                }

                return $this->commandesVetpoint === [] ? Http::response('{}', 200, ['Content-Type' => 'application/json']) : Http::response($this->commandesVetpoint);
            }
            if ($chemin === '/v1/order' && $requete->method() === 'POST') {
                if ($this->refus !== null) {
                    return Http::response($this->refus['corps'], $this->refus['statut']);
                }
                if ($this->panneAvantVetpoint) {
                    // La connexion tombe avant que Vetpoint ne reçoive rien
                    throw new ConnectionException('Connexion refusée');
                }
                if ($this->envoiPerdu) {
                    // Vetpoint l'a reçue, mais la réponse se perd en route
                    $this->commandesVetpoint[] = ['orderId' => 'V-7001', 'orderDate' => now()->toIso8601String(), 'orderItems' => array_map(fn (array $ligne) => ['productId' => $ligne['productId'], 'quantity' => $ligne['quantity'], 'orderNote' => $this->sansNote ? null : ($ligne['orderNote'] ?? null)], $requete->data()['orderItems'] ?? [])];
                    throw new ConnectionException('Délai dépassé');
                }

                if ($this->reponseEnvoi !== null) {
                    return str_starts_with($this->reponseEnvoi, 'texte:') ? Http::response(substr($this->reponseEnvoi, 6)) : Http::response($this->reponseEnvoi, 200, ['Content-Type' => 'application/json']);
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
            ->assertJsonPath('message', 'Vetpoint ne répond pas : la commande est peut-être partie. Réessayez dans une minute : elle sera d’abord cherchée chez Vetpoint.');
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

    /* ——— 2e passe (28.10.2026) ——— */

    /** Réponse perdue, puis la recherche refusée (trop d'appels) : la commande n'est jamais renvoyée sans avoir été cherchée. */
    public function test_une_recherche_refusee_apres_une_reponse_perdue_ne_libere_pas_la_commande(): void
    {
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;

        foreach ([429, 401, 403] as $statut) {
            $this->travel(2)->minutes();
            $this->rechercheRefusee = $statut;
            $this->envoyer($commande)->assertStatus($statut === 429 ? 429 : 409);
        }
        $this->travel(2)->minutes();
        $this->rechercheRefusee = null;
        $this->envoyer($commande)->assertOk()->assertJsonPath('data.vetpoint.numero', 'V-7001');

        $this->assertCount(1, $this->envois());
    }

    /** Réponse 200 sans numéro lisible : rien n'est gardé de faux ; l'essai suivant retrouve la commande chez Vetpoint. */
    public function test_une_reponse_d_envoi_sans_numero_lisible_ne_garde_rien_de_faux(): void
    {
        foreach (['{"id":"X1"}', '[]', 'null', '{"orderId":null}'] as $corps) {
            $commande = $this->brouillon();
            $this->reponseEnvoi = $corps;

            $this->envoyer($commande)->assertStatus(503);

            $this->assertNull($commande->refresh()->vetpoint_commande_id, $corps);
            $this->assertSame(StatutCommandeFournisseur::Brouillon, $commande->statut);
        }
    }

    /** Sans note interne rendue par Vetpoint : la commande se reconnaît à ses produits, ses quantités et son heure. */
    public function test_une_autre_commande_aux_memes_produits_n_est_jamais_prise_pour_la_sienne(): void
    {
        // Passée sur vetpoint.ch, sans note, après la création du brouillon
        $this->commandesVetpoint = [['orderId' => 'WEB-1', 'orderDate' => now()->addMinutes(30)->toIso8601String(), 'orderItems' => [['productId' => '913-306334', 'quantity' => 4]]]];
        $commande = $this->brouillon();
        $this->travel(1)->hour();
        $this->panneAvantVetpoint = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->panneAvantVetpoint = false;

        // Des commandes dans la fenêtre, aucune note : Vetpoint ne dit pas si
        // la nôtre est partie. Ni prise pour une autre, ni renvoyée à l'aveugle
        $this->travel(2)->minutes();
        $this->envoyer($commande)->assertStatus(409);

        $this->assertCount(1, $this->envois(), 'Seul l’essai en panne');
        $this->assertNull($commande->refresh()->vetpoint_commande_id);
    }

    /** Même sans aucune note rendue par Vetpoint : pas de commande devinée à ses produits. */
    public function test_sans_note_rendue_rien_n_est_devine(): void
    {
        $this->sansNote = true;
        $premiere = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($premiere)->assertStatus(503);
        $this->envoiPerdu = false;
        $seconde = $this->brouillon();
        $this->panneAvantVetpoint = true;
        $this->envoyer($seconde)->assertStatus(503);
        $this->panneAvantVetpoint = false;

        $this->travel(2)->minutes();
        $this->envoyer($seconde)->assertStatus(409);

        // La seconde n'est ni confondue avec la première, ni renvoyée à l'aveugle
        $this->assertCount(2, $this->envois());
        $this->assertNull($seconde->refresh()->vetpoint_commande_id);
    }

    /** La recherche couvre depuis la création du brouillon : un essai tardif en panne ne fait pas oublier le premier envoi. */
    public function test_la_recherche_couvre_depuis_la_creation_du_brouillon(): void
    {
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;

        // Trois jours plus tard, un essai dont la recherche tombe en panne, puis un dernier essai
        $this->travel(3)->days();
        $this->rechercheRefusee = 500;
        $this->envoyer($commande)->assertStatus(503);
        $this->rechercheRefusee = null;
        $this->travel(2)->minutes();
        $this->envoyer($commande)->assertOk();

        $this->assertCount(1, $this->envois());
        $recherches = array_values(array_filter($this->appels, fn (array $a) => $a['methode'] === 'GET' && $a['chemin'] === '/v1/order'));
        $this->assertSame('2026-10-27', end($recherches)['corps']['fromDate']);
    }

    /** Une clinique sœur qui a sa clé : la commande qu'elle reçoit part avec sa clé à elle. */
    public function test_une_commande_livree_a_une_clinique_soeur_part_avec_la_cle_de_la_soeur(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ConnexionVetpoint::withoutGlobalScopes()->create(['clinique_id' => $soeur->id, 'cle' => self::CLE_SOEUR, 'verifiee_le' => now()]);
        $commande = $this->brouillon();
        $commande->forceFill(['clinique_id' => $soeur->id])->save();

        $this->refus = ['statut' => 422, 'corps' => ['errorTyoe' => '4002', 'errorMessage' => 'invalid']];
        $this->envoyer($commande)->assertUnprocessable();
        $this->assertNull($commande->refresh()->vetpoint_envoi_le);
        $this->refus = null;

        $this->envoyer($commande)->assertOk()->assertJsonPath('data.vetpoint.numero', 'V-7001');
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $commande->refresh()->statut);
        // Chaque appel avec la clé de la sœur, jamais celle de la clinique du jeton
        $this->assertSame([self::CLE_SOEUR], array_values(array_unique(array_column($this->appels, 'cle'))));
    }

    public function test_les_refus_sans_code_ou_en_allemand_restent_justes(): void
    {
        $cas = [
            [400, ['errorMessage' => 'Invalid date format'], 'Vetpoint a refusé la commande. Son message : « Invalid date format ».'],
            [422, ['errorMessage' => "Das Produkt 'Hill's i/d 2 kg' hat eine Mindestbestellmenge von 3"], 'Le produit « Hill\'s i/d 2 kg » se commande par 3 au moins.'],
            [422, ['errorMessage' => 'Lieferung 2026 nicht möglich'], 'Vetpoint a refusé la commande. Son message : « Lieferung 2026 nicht möglich ».'],
            // Un nombre au milieu du message n'est pas un code (1065 : adresse incomplète)
            [422, ['errorMessage' => 'Bestellung von 1065 Stück nicht möglich'], 'Vetpoint a refusé la commande. Son message : « Bestellung von 1065 Stück nicht möglich ».'],
            // « errorType » bien écrit, au cas où Vetpoint corrige sa faute
            [422, ['errorType' => '4002', 'errorMessage' => 'Invalid shipping address provided'], 'Cette adresse de livraison n’est pas valable chez Vetpoint.'],
            [422, ['errorTyoe' => '1064', 'errorMessage' => 'express'], 'Pas de date souhaitée en express : la livraison est la plus rapide possible.'],
            [422, ['errorTyoe' => '4001', 'errorMessage' => 'no address'], 'Choisissez une adresse de livraison.'],
            [422, ['errorTyoe' => '1066', 'errorMessage' => 'postal code'], 'L’adresse de livraison est incomplète chez Vetpoint.'],
        ];
        foreach ($cas as [$statut, $corps, $message]) {
            $commande = $this->brouillon();
            $this->refus = ['statut' => $statut, 'corps' => $corps];

            $this->assertSame($message, $this->envoyer($commande)->json('errors.vetpoint.0'));
        }
    }

    /** La date souhaitée part avec le décalage horaire de la clinique. */
    public function test_la_date_souhaitee_part_avec_le_decalage_horaire_de_la_clinique(): void
    {
        $commande = $this->brouillon();

        $this->envoyer($commande, ['adresse_id' => 'A2', 'livraison' => 'standard', 'date_souhaitee' => '2026-11-03'])->assertOk();

        // Midi : le jour reste le même quel que soit le fuseau où Vetpoint le lit
        $this->assertSame('2026-11-03T12:00:00+01:00', $this->envois()[0]['corps']['desiredDeliveryDate']);
    }

    /** Vetpoint ne donne encore aucun prix (ou un prix nul) : rien n'est repris, et la réponse le dit. */
    public function test_sans_prix_reels_rien_n_est_repris_et_la_reponse_le_dit(): void
    {
        $commande = $this->envoyee();
        $this->detail['orderItems'][0]['unitPrice'] = 0;

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)
            ->assertOk()->assertJsonPath('data.reception_vetpoint.lu', true)->assertJsonPath('data.reception_vetpoint.prix_repris', 0)
            ->assertJsonPath('data.reception_vetpoint.message', 'Vetpoint ne donne pas encore les prix réels : le stock est entré avec les prix de la commande.');

        $this->assertSame('10.00', (string) $commande->lignes()->first()->cout);
    }

    /** Un prix négatif n'est pas repris ; une partie des lignes seulement : la réponse dit combien. */
    public function test_un_prix_negatif_n_est_pas_repris_et_la_reprise_partielle_se_dit(): void
    {
        $commande = $this->brouillon(['913-306334' => 'Rimadyl 50 mg', '913-111111' => 'Metacam 1.5']);
        $this->envoyer($commande)->assertOk();
        $this->detail = ['orderId' => 'V-7001', 'orderItems' => [
            ['productId' => '913-306334', 'quantity' => 4, 'unitPrice' => 12.35],
            ['productId' => '913-111111', 'quantity' => 4, 'unitPrice' => -3],
        ]];

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)
            ->assertOk()->assertJsonPath('data.reception_vetpoint.prix_repris', 1)
            ->assertJsonPath('data.reception_vetpoint.message', 'Prix réels de Vetpoint repris pour 1 ligne sur 2 ; les autres gardent le prix de la commande.');

        $this->assertSame(['12.35', '10.00'], $commande->lignes()->orderBy('id')->pluck('cout')->map(fn ($c) => (string) $c)->all());
    }

    /** Un envoi par Vetpoint en suspens : ni envoi classique ni suppression, pour ne pas commander deux fois. */
    public function test_un_envoi_vetpoint_en_suspens_bloque_l_envoi_classique_et_la_suppression(): void
    {
        $commande = $this->brouillon();
        $commande->forceFill(['vetpoint_envoi_le' => now()->subMinutes(5)])->save();
        $message = 'Cette commande est peut-être déjà partie par Vetpoint : renvoyez-la par Vetpoint, elle y sera d’abord cherchée.';

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/envoyer", [], $this->entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['statut' => $message]);
        $this->deleteJson("/api/v1/stocks/commandes/{$commande->id}", [], $this->entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['statut' => $message]);
        $ligne = $commande->lignes()->first();
        $this->patchJson("/api/v1/stocks/commandes/{$commande->id}/lignes/{$ligne->id}", ['quantite' => 9], $this->entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['statut' => $message]);
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $commande->refresh()->statut);
        $this->assertSame(4, (int) $ligne->refresh()->quantite);
    }

    /** Deux articles du catalogue reliés au même produit Vetpoint : chacun prend son prix réel. */
    public function test_deux_articles_relies_au_meme_produit_vetpoint_prennent_chacun_leur_prix(): void
    {
        $commande = $this->brouillon(['913-306334' => 'Rimadyl 50 mg']);
        $organisation = $this->clinique->societe->organisation_id;
        $autre = Produit::factory()->create(['organisation_id' => $organisation, 'nom' => 'Rimadyl (stock 2)', 'cout' => 10]);
        ProduitVetpoint::query()->create(['produit_id' => $autre->id, 'vetpoint_id' => '913-306334', 'nom' => 'Rimadyl 50 mg']);
        CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commande->id, 'produit_id' => $autre->id, 'quantite' => 2, 'cout' => 10]);
        $this->envoyer($commande)->assertOk();
        // Dans l'autre ordre, à des prix différents : chacun par sa quantité
        $this->detail = ['orderId' => 'V-7001', 'orderItems' => [
            ['productId' => '913-306334', 'quantity' => 2, 'unitPrice' => 11, 'expirationDate' => '2027-06-30'],
            ['productId' => '913-306334', 'quantity' => 4, 'unitPrice' => 12.35, 'expirationDate' => '2027-05-31'],
        ]];

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)->assertOk();

        $this->assertSame(['12.35', '11.00'], $commande->lignes()->orderBy('id')->pluck('cout')->map(fn ($c) => (string) $c)->all());
    }

    /** Une réponse texte sans chiffre (« OK ») n'est pas un numéro de commande. */
    public function test_une_reponse_texte_sans_numero_n_est_pas_gardee(): void
    {
        $commande = $this->brouillon();
        $this->reponseEnvoi = 'texte:OK';

        $this->envoyer($commande)->assertStatus(503);

        $this->assertNull($commande->refresh()->vetpoint_commande_id);
    }

    /** Une ligne changée dans un autre onglet pendant l'envoi : ce qui part chez Vetpoint est ce qui est gardé. */
    public function test_une_ligne_changee_pendant_l_envoi_part_comme_elle_est_gardee(): void
    {
        $commande = $this->brouillon();
        $ligne = $commande->lignes()->first();
        $this->pendantLesAdresses = fn () => $ligne->forceFill(['quantite' => 10])->save();

        $this->envoyer($commande)->assertOk();

        $this->assertSame(10, $this->envois()[0]['corps']['orderItems'][0]['quantity']);
        $this->assertSame(10, (int) $ligne->refresh()->quantite);
    }

    /** Retrouvée après une réponse perdue : la commande garde les choix avec lesquels elle est partie. */
    public function test_une_commande_retrouvee_garde_ses_choix_d_envoi(): void
    {
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande, ['adresse_id' => 'A1', 'livraison' => 'express'])->assertStatus(503);
        $this->envoiPerdu = false;

        $this->travel(2)->minutes();
        $this->envoyer($commande, ['adresse_id' => 'A2', 'livraison' => 'standard'])->assertOk()
            ->assertJsonPath('data.vetpoint.livraison', 'express');

        $this->assertSame('A1', $commande->refresh()->vetpoint_adresse_id);
    }

    /** Une adresse trop longue ou mal formée : le refus est en français. */
    public function test_une_adresse_mal_formee_est_refusee_en_francais(): void
    {
        $commande = $this->brouillon();

        foreach ([str_repeat('A', 41), ['A2']] as $adresse) {
            $this->envoyer($commande, ['adresse_id' => $adresse, 'livraison' => 'standard'])
                ->assertUnprocessable()->assertJsonValidationErrors(['adresse_id' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.']);
        }
    }

    /* ——— 4e passe (28.10.2026) ——— */

    /** Une liste enveloppée ({"orders": [...]}) se lit comme une liste : la commande perdue est retrouvée, pas renvoyée. */
    public function test_une_liste_de_commandes_enveloppee_se_lit(): void
    {
        $this->listeCommandes = fn (array $commandes): string => json_encode(['orders' => $commandes]);
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;

        $this->travel(2)->minutes();
        $this->envoyer($commande)->assertOk()->assertJsonPath('data.vetpoint.numero', 'V-7001');

        $this->assertCount(1, $this->envois());
    }

    /** Une réponse de forme inconnue, ou des commandes sans note rendue : rien ne repart, la commande reste en suspens. */
    public function test_une_liste_illisible_ou_sans_note_ne_fait_jamais_repartir_la_commande(): void
    {
        foreach ([
            fn (array $commandes): string => json_encode(['total' => 1]),
            fn (array $commandes): string => json_encode(['data' => ['items' => $commandes]]),
        ] as $forme) {
            $this->listeCommandes = $forme;
            $commande = $this->brouillon();
            $this->envoiPerdu = true;
            $this->envoyer($commande)->assertStatus(503);
            $this->envoiPerdu = false;
            $avant = count($this->envois());

            $this->travel(2)->minutes();
            $this->envoyer($commande)->assertStatus(503);

            $this->assertCount($avant, $this->envois());
            $this->assertNotNull($commande->refresh()->vetpoint_envoi_le);
        }

        // Des commandes rendues, mais aucune ligne ne porte de note : Vetpoint ne dit pas laquelle est la nôtre
        $this->listeCommandes = null;
        $this->commandesVetpoint = [];
        $this->sansNote = true;
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;
        $avant = count($this->envois());
        $this->travel(2)->minutes();

        $this->envoyer($commande)->assertStatus(409)
            ->assertJsonPath('message', 'Vetpoint ne dit pas si cette commande est déjà partie : vérifiez sur vetpoint.ch, puis abandonnez l’envoi par Vetpoint si elle n’y est pas.');
        $this->assertCount($avant, $this->envois());
    }

    /** Envoi en suspens : l'abandon (après vérification sur vetpoint.ch) rend le brouillon. */
    public function test_l_envoi_en_suspens_s_abandonne(): void
    {
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;

        // Tout de suite : elle part peut-être en ce moment
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/vetpoint/abandonner", [], $this->entetes)->assertStatus(409);
        $this->travel(2)->minutes();
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/vetpoint/abandonner", [], $this->entetes)
            ->assertOk()->assertJsonPath('data.vetpoint', null)->assertJsonPath('data.statut', 'draft');

        $this->assertNull($commande->refresh()->vetpoint_envoi_le);
        $this->deleteJson("/api/v1/stocks/commandes/{$commande->id}", [], $this->entetes)->assertNoContent();
    }

    /** Pendant une reprise, des lignes changées ne rendent pas la commande : elle reste en suspens. */
    public function test_des_lignes_changees_pendant_une_reprise_gardent_la_commande_en_suspens(): void
    {
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;
        $this->travel(2)->minutes();
        $this->pendantLesAdresses = fn () => ProduitVetpoint::query()->delete();

        $this->envoyer($commande)->assertUnprocessable();

        $this->assertNotNull($commande->refresh()->vetpoint_envoi_le);
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/envoyer", [], $this->entetes)->assertUnprocessable();
    }

    /** La clinique de livraison changée pendant la lecture des adresses : rien ne part avec la clé de l'autre clinique. */
    public function test_la_clinique_changee_pendant_l_envoi_n_envoie_rien(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $commande = $this->brouillon();
        $this->pendantLesAdresses = fn () => CommandeFournisseur::query()->withoutGlobalScopes()->whereKey($commande->id)->update(['clinique_id' => $soeur->id]);

        $this->envoyer($commande)->assertStatus(409);

        $this->assertSame([], $this->envois());
        $this->assertNull($commande->refresh()->vetpoint_envoi_le);
    }

    /** La clinique de livraison ne se change pas pendant un envoi en suspens. */
    public function test_la_destination_ne_se_change_pas_pendant_un_envoi_en_suspens(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $commande = $this->brouillon();
        $commande->forceFill(['vetpoint_envoi_le' => now()->subMinutes(5)])->save();

        $this->patchJson("/api/v1/stocks/commandes/{$commande->id}", ['clinique_id' => $soeur->id], $this->entetes)->assertUnprocessable();

        $this->assertSame($this->clinique->id, $commande->refresh()->clinique_id);
    }

    /** Un numéro déjà gardé par une autre commande n'est jamais repris. */
    public function test_un_numero_deja_garde_n_est_jamais_repris(): void
    {
        $autre = $this->brouillon();
        $autre->forceFill(['vetpoint_commande_id' => 'V-7001', 'statut' => StatutCommandeFournisseur::Envoyee])->save();
        $commande = $this->brouillon();
        // Même note (numéro réutilisé ailleurs) que la commande perdue, numéro déjà pris
        $this->commandesVetpoint = [['orderId' => 'V-7001', 'orderDate' => now()->toIso8601String(), 'orderItems' => [['productId' => '913-306334', 'quantity' => 4, 'orderNote' => 'Veterinary '.$commande->numero]]]];
        $commande->forceFill(['vetpoint_envoi_le' => now()->subMinutes(5)])->save();

        $this->envoyer($commande)->assertOk();

        $this->assertCount(1, $this->envois());
    }

    /** Un refus 404 en page HTML (un proxy) n'est pas recopié tel quel. */
    public function test_un_refus_en_page_html_n_est_pas_recopie(): void
    {
        $commande = $this->brouillon();
        $this->refus = ['statut' => 404, 'corps' => '<html><body><h1>404 Not Found</h1></body></html>'];

        $this->assertSame('Vetpoint a refusé la commande.', $this->envoyer($commande)->json('errors.vetpoint.0'));
    }

    public function test_les_autres_codes_d_adresse_et_le_400_sans_message(): void
    {
        foreach ([[422, ['errorTyoe' => '1065'], 'L’adresse de livraison est incomplète chez Vetpoint.'], [422, ['errorTyoe' => '1067'], 'L’adresse de livraison est incomplète chez Vetpoint.'], [422, ['errorTyoe' => '1068'], 'L’adresse de livraison est incomplète chez Vetpoint.'], [400, ['statusCode' => 400], 'Un produit de la commande est introuvable chez Vetpoint : vérifiez ses liens.']] as [$statut, $corps, $message]) {
            $commande = $this->brouillon();
            $this->refus = ['statut' => $statut, 'corps' => $corps];

            $this->assertSame($message, $this->envoyer($commande)->json('errors.vetpoint.0'));
        }
    }

    /** Envoi en cours (en suspens) : la ressource le dit. */
    public function test_la_ressource_dit_l_envoi_en_suspens(): void
    {
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);

        $this->getJson('/api/v1/stocks/commandes', $this->entetes)->assertOk()
            ->assertJsonPath('data.0.vetpoint.envoi_en_cours', true)->assertJsonPath('data.0.vetpoint.numero', null);
    }

    /** Un article délié après l'envoi garde son prix réel à la réception (le lien est gardé sur la ligne). */
    public function test_un_article_delie_apres_l_envoi_garde_son_prix_reel(): void
    {
        $commande = $this->envoyee();
        ProduitVetpoint::query()->delete();

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)->assertOk()
            ->assertJsonPath('data.reception_vetpoint.prix_repris', 1);

        $this->assertSame('12.35', (string) $commande->lignes()->first()->cout);
    }

    /** Prix repris mais aucune péremption donnée : la réponse le dit (les lots prennent celle du catalogue). */
    public function test_sans_peremption_de_vetpoint_la_reponse_le_dit(): void
    {
        $commande = $this->envoyee();
        unset($this->detail['orderItems'][0]['expirationDate']);

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", [], $this->entetes)->assertOk()
            ->assertJsonPath('data.reception_vetpoint.message', 'Prix réels de Vetpoint repris ; Vetpoint ne donne pas encore les péremptions : les lots prennent celle du catalogue.');
    }

    /* ——— 5e passe (28.10.2026) ——— */

    /** Notre note retrouvée, mais sans numéro lisible : rien ne repart (on sait qu'elle est partie). */
    public function test_notre_note_sans_numero_lisible_ne_fait_pas_repartir(): void
    {
        $this->listeCommandes = fn (array $commandes): string => json_encode(array_map(function (array $c): array {
            $c['id'] = $c['orderId'];
            unset($c['orderId']);

            return $c;
        }, $commandes));
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;
        $avant = count($this->envois());

        $this->travel(2)->minutes();
        $this->envoyer($commande)->assertStatus(409);

        $this->assertCount($avant, $this->envois());
    }

    /** Une note rendue avec une espace ou une casse différente reste la nôtre. */
    public function test_une_note_rendue_un_peu_changee_reste_la_notre(): void
    {
        $this->listeCommandes = fn (array $commandes): string => json_encode(array_map(function (array $c): array {
            $c['orderItems'] = array_map(fn (array $e): array => [...$e, 'orderNote' => '  '.mb_strtoupper((string) $e['orderNote']).' '], $c['orderItems']);

            return $c;
        }, $commandes));
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;

        $this->travel(2)->minutes();
        $this->envoyer($commande)->assertOk()->assertJsonPath('data.vetpoint.numero', 'V-7001');

        $this->assertCount(1, $this->envois());
    }

    /** Deux de nos commandes en suspens : chacune ne retrouve que la sienne. */
    public function test_une_commande_ne_retrouve_que_la_sienne(): void
    {
        $premiere = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($premiere)->assertStatus(503);
        $this->envoiPerdu = false;
        $seconde = $this->brouillon();
        $this->panneAvantVetpoint = true;
        $this->envoyer($seconde)->assertStatus(503);
        $this->panneAvantVetpoint = false;
        $avant = count($this->envois());

        $this->travel(2)->minutes();
        $this->envoyer($seconde)->assertOk();

        // La seconde est repartie vraiment : la commande de la première n'est pas la sienne
        $this->assertCount($avant + 1, $this->envois());
    }

    /** L'abandon : seulement un envoi en suspens de son organisation. */
    public function test_l_abandon_ne_vaut_que_pour_un_envoi_en_suspens_de_son_organisation(): void
    {
        // Avant toute requête : ensuite, le contexte refuse d'écrire hors de son organisation
        $autreClinique = Clinique::factory()->create();
        $ailleurs = CommandeFournisseur::withoutEvents(fn () => CommandeFournisseur::factory()->create(['clinique_id' => $autreClinique->id, 'vetpoint_envoi_le' => now()->subMinutes(5)]));
        $libre = $this->brouillon();
        $this->postJson("/api/v1/stocks/commandes/{$libre->id}/vetpoint/abandonner", [], $this->entetes)->assertUnprocessable();
        $envoyee = $this->envoyee();
        $this->postJson("/api/v1/stocks/commandes/{$envoyee->id}/vetpoint/abandonner", [], $this->entetes)->assertUnprocessable();

        $this->postJson("/api/v1/stocks/commandes/{$ailleurs->id}/vetpoint/abandonner", [], $this->entetes)->assertNotFound();
        $this->assertNotNull($ailleurs->refresh()->vetpoint_envoi_le);
    }

    /** Aucune note ne correspond, mais une commande aux mêmes produits et quantités est là : on ne renvoie pas, on demande de vérifier. */
    public function test_une_commande_aux_memes_produits_fait_verifier_au_lieu_de_renvoyer(): void
    {
        $commande = $this->brouillon();
        // Note tronquée par Vetpoint : elle ne correspond plus
        $this->listeCommandes = fn (array $commandes): string => json_encode(array_map(function (array $c): array {
            $c['orderItems'] = array_map(fn (array $e): array => [...$e, 'orderNote' => mb_substr((string) $e['orderNote'], 0, 20)], $c['orderItems']);

            return $c;
        }, $commandes));
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;
        $avant = count($this->envois());

        $this->travel(2)->minutes();
        $this->envoyer($commande)->assertStatus(409);

        $this->assertCount($avant, $this->envois());
    }

    /** Une note aux espaces intérieurs doublés reste la nôtre. */
    public function test_une_note_aux_espaces_doubles_reste_la_notre(): void
    {
        $this->listeCommandes = fn (array $commandes): string => json_encode(array_map(function (array $c): array {
            $c['orderItems'] = array_map(fn (array $e): array => [...$e, 'orderNote' => str_replace(' ', "  \t", (string) $e['orderNote'])], $c['orderItems']);

            return $c;
        }, $commandes));
        $commande = $this->brouillon();
        $this->envoiPerdu = true;
        $this->envoyer($commande)->assertStatus(503);
        $this->envoiPerdu = false;

        $this->travel(2)->minutes();
        $this->envoyer($commande)->assertOk();

        $this->assertCount(1, $this->envois());
    }

    /** Une commande de la fenêtre aux produits ou quantités différents ne bloque pas la reprise ; une d'avant le brouillon non plus. */
    public function test_une_autre_commande_differente_ou_plus_ancienne_ne_bloque_pas_la_reprise(): void
    {
        $commande = $this->brouillon(['913-306334' => 'Rimadyl 50 mg', '913-111111' => 'Metacam 1.5']);
        $this->commandesVetpoint = [
            // Passée sur vetpoint.ch : même produits, autres quantités
            ['orderId' => 'WEB-1', 'orderDate' => now()->toIso8601String(), 'orderItems' => [['productId' => '913-306334', 'quantity' => 2, 'orderNote' => null], ['productId' => '913-111111', 'quantity' => 4, 'orderNote' => null]]],
            // Mêmes produits et quantités, mais la veille de la création du brouillon
            ['orderId' => 'WEB-2', 'orderDate' => now()->subHours(20)->toIso8601String(), 'orderItems' => [['productId' => '913-111111', 'quantity' => 4, 'orderNote' => null], ['productId' => '913-306334', 'quantity' => 4, 'orderNote' => null]]],
            // Une de nos commandes avec sa note : Vetpoint rend les notes
            ['orderId' => 'V-6000', 'orderDate' => now()->toIso8601String(), 'orderItems' => [['productId' => '913-999999', 'quantity' => 1, 'orderNote' => 'Veterinary BC-2026-001']]],
        ];
        $commande->forceFill(['vetpoint_envoi_le' => now()->subMinutes(5)])->save();

        $this->envoyer($commande)->assertOk();

        $this->assertCount(1, $this->envois());
    }

    /** Mêmes produits et quantités, dans un autre ordre et après la création du brouillon : on fait vérifier. */
    public function test_les_memes_lignes_dans_un_autre_ordre_font_verifier(): void
    {
        $commande = $this->brouillon(['913-306334' => 'Rimadyl 50 mg', '913-111111' => 'Metacam 1.5']);
        $this->commandesVetpoint = [
            ['orderId' => 'WEB-3', 'orderDate' => now()->addMinutes(2)->toIso8601String(), 'orderItems' => [['productId' => '913-111111', 'quantity' => 4, 'orderNote' => null], ['productId' => '913-306334', 'quantity' => 4, 'orderNote' => null]]],
            ['orderId' => 'V-6000', 'orderDate' => now()->toIso8601String(), 'orderItems' => [['productId' => '913-999999', 'quantity' => 1, 'orderNote' => 'Veterinary BC-2026-001']]],
        ];
        $this->travel(5)->minutes();
        $commande->forceFill(['vetpoint_envoi_le' => now()->subMinutes(2)])->save();

        $this->envoyer($commande)->assertStatus(409);

        $this->assertSame([], $this->envois());
    }
}
