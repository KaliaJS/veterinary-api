<?php

namespace Tests\Feature\Stocks;

use App\Enums\StatutCommandeFournisseur;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\ConnexionVetpoint;
use App\Models\ProductOrderSetting;
use App\Models\Produit;
use App\Models\ProduitVetpoint;
use App\Models\Stock;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Vetpoint, première centrale derrière l'interface des connecteurs
 * (03.11.2026) : le panier automatique y part seul, à l'adresse par défaut
 * du compte de la clinique, en livraison standard ; un article que Vetpoint
 * dit indisponible, ou qui n'y est pas relié, passe à sa source suivante.
 */
class VetpointConnectorTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string URL = 'https://vetpoint.test/api/tapsw';

    private const string CLE = 'CBFGLESURB4CLETESTVETPOINTAB12';

    private Clinique $clinique;

    private string $jeton;

    /** @var list<array{methode: string, chemin: string, corps: array<mixed>}> */
    private array $appels = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Zurich'));
        config(['services.vetpoint.url' => self::URL, 'purchasing.auto_send_api' => true]);
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        ConnexionVetpoint::query()->create(['clinique_id' => $this->clinique->id, 'cle' => self::CLE, 'verifiee_le' => now()]);

        Http::fake(function (Request $requete) {
            $chemin = substr((string) parse_url($requete->url(), PHP_URL_PATH), strlen('/api/tapsw'));
            $this->appels[] = ['methode' => $requete->method(), 'chemin' => $chemin, 'corps' => $requete->data()];

            return match (true) {
                $chemin === '/v1/product/VP-1' => Http::response(['productId' => 'VP-1', 'name' => 'Metacam', 'available' => true]),
                $chemin === '/v1/product/VP-2' => Http::response(['productId' => 'VP-2', 'name' => 'Bravecto', 'available' => false]),
                $chemin === '/v1/shipping-address' => Http::response([
                    ['shippingAddressId' => 'A1', 'company' => 'Clinique', 'address1' => 'Rue 1', 'postalCode' => '1000', 'city' => 'Lausanne', 'defaultShippingAddress' => false],
                    ['shippingAddressId' => 'A2', 'company' => 'Clinique', 'address1' => 'Rue 2', 'postalCode' => '1000', 'city' => 'Lausanne', 'defaultShippingAddress' => true],
                ]),
                $chemin === '/v1/order' && $requete->method() === 'POST' => Http::response(['orderId' => '498500']),
                default => Http::response([], 404),
            };
        });
    }

    private function article(string $nom, ?string $vetpointId, array $fournisseurs): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'nom' => $nom, 'seuil_alerte' => 3, 'cout' => 10, 'est_stupefiant' => false,
        ]);
        ProductOrderSetting::query()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'is_auto_order' => true, 'reorder_up_to' => 6]);
        if ($vetpointId !== null) {
            ProduitVetpoint::query()->create(['produit_id' => $produit->id, 'vetpoint_id' => $vetpointId, 'nom' => $nom]);
        }
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 4]);
        foreach ($fournisseurs as $rang => $fournisseur) {
            $produit->sources()->create(['supplier_id' => $fournisseur->id, 'rank' => $rang + 1, 'cost' => 12]);
        }
        $this->postJson('/api/v1/stocks/produits/'.$produit->id.'/ajuster', ['quantite' => -1, 'motif' => 'usage'], $this->enTetes($this->jeton))->assertOk();

        return $produit;
    }

    public function test_le_panier_part_seul_par_vetpoint_et_l_indisponible_passe_a_la_source_suivante(): void
    {
        $vetpoint = Supplier::query()->create(['clinique_id' => $this->clinique->id, 'name' => 'Vetpoint', 'channel' => 'api', 'connector' => 'vetpoint', 'minimum_order' => 10]);
        $provet = Supplier::query()->create(['clinique_id' => $this->clinique->id, 'name' => 'Provet AG', 'channel' => 'manual']);
        $metacam = $this->article('Metacam', 'VP-1', [$vetpoint]);
        $bravecto = $this->article('Bravecto', 'VP-2', [$vetpoint, $provet]);
        // Pas relié à Vetpoint : il ne peut pas y partir
        $gants = $this->article('Gants', null, [$vetpoint, $provet]);

        // L'avis part d'abord ; le panier, 12 heures plus tard
        $this->artisan('orders:auto-send')->assertSuccessful();
        $this->travel(12 * 60 + 5)->minutes();
        $this->artisan('orders:auto-send')->assertSuccessful();

        $parti = CommandeFournisseur::query()->withoutGlobalScopes()->where('supplier_id', $vetpoint->id)->sole();
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $parti->statut);
        $this->assertSame('498500', $parti->vetpoint_commande_id);
        $envoi = collect($this->appels)->first(fn (array $a): bool => $a['methode'] === 'POST' && $a['chemin'] === '/v1/order');
        $this->assertSame('A2', $envoi['corps']['shippingAddressId']);
        $this->assertSame('STANDARD', $envoi['corps']['deliveryType']);
        $this->assertSame(['VP-1'], array_column($envoi['corps']['orderItems'], 'productId'));

        $chezProvet = CommandeFournisseur::query()->withoutGlobalScopes()->where('supplier_id', $provet->id)->with('lignes')->sole();
        $this->assertEqualsCanonicalizing([$bravecto->id, $gants->id], $chezProvet->lignes->pluck('produit_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(['Vetpoint'], $chezProvet->lignes->pluck('moved_from')->unique()->values()->all());
        $this->assertSame($metacam->id, (int) $parti->lignes()->sole()->produit_id);
    }
}
