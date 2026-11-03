<?php

namespace Tests\Feature\Hospitalisation;

use App\Enums\Espece;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\MouvementStock;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use App\Models\Stock;
use App\Services\FactureSejour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Hospitalisation, neuvième vérification (03.11.2026) : un compte sans écart
 * s'écrit aussi ; un séjour sorti montre sa vraie facture ; numéro de
 * flacon et dernier compte lus sur la ligne de stock verrouillée ;
 * contenance figée celle vraiment utilisée ; stock verrouillé d'abord.
 */
class RoundNinthCheckTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:05', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Rachel Solal']);
    }

    private function sejour(): Sejour
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $chien = Patient::factory()->for($client)->create(['espece' => Espece::Chien, 'nom' => 'Tao']);

        return Sejour::factory()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $chien->id, 'client_id' => $client->id,
            'box' => 2, 'statut' => 'surv', 'perfusion' => null,
            'admis_le' => CarbonImmutable::parse('2026-11-01 09:15', 'Europe/Zurich')->utc(),
        ]);
    }

    private function url(Sejour $sejour, string $suite = ''): string
    {
        return "/api/v1/hospitalisation/sejours/{$sejour->id}{$suite}";
    }

    private function produit(int $fermes = 3, float $entame = 0, float $contenance = 20, string $unite = 'flacon'): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Cerenia 10 mg/ml', 'categorie' => 'med',
            'unite' => $unite, 'prix' => 60, 'est_stupefiant' => false, 'dose_unit' => 'ml', 'content_per_unit' => $contenance,
        ]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $fermes, 'opened_rest' => $entame, 'opened_at' => $entame > 0 ? now()->subDay() : null]));

        return $produit;
    }

    private function stock(Produit $produit): array
    {
        $stock = Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();

        return [$stock->quantite, (float) $stock->opened_rest];
    }

    private function prise(Sejour $sejour, ?Produit $produit, int $minute = 840, float $dose = 2): SejourSoin
    {
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'dose_amount' => $dose, 'product_id' => $produit?->id, 'times' => [$minute], 'starts_at' => $sejour->admis_le]);

        return SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-03', 'prevu_a' => $minute, 'acte' => 'Maropitant', 'dose_amount' => $dose, 'produit_id' => $produit?->id]);
    }

    private function give(Sejour $sejour, SejourSoin $prise, array $data = []): TestResponse
    {
        return $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), $data, $this->enTetes($this->jeton));
    }

    private function cancel(Sejour $sejour, SejourSoin $prise): array
    {
        return $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))->assertOk()->json('stock') ?? [];
    }

    private function countRest(Produit $produit, float $rest): TestResponse
    {
        return $this->patchJson("/api/v1/stocks/produits/{$produit->id}/entame", ['opened_rest' => $rest, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertOk();
    }

    private function stockRow(Produit $produit): Stock
    {
        return Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
    }

    // ————— Moyen 1 : un compte sans écart s'écrit aussi —————

    public function test_un_compte_qui_confirme_le_reste_s_ecrit_et_rien_ne_revient_ensuite(): void
    {
        $produit = $this->produit(fermes: 3, entame: 20);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, dose: 8);
        $this->give($sejour, $prise)->assertOk();
        $this->assertSame([3, 12.0], $this->stock($produit));

        $this->countRest($produit, 12)->assertJsonPath('meta.gap', 0);
        $line = MouvementStock::withoutGlobalScopes()->where('libelle', 'Inventaire du flacon entamé')->sole();
        $this->assertSame([0, 0.0, $prise->refresh()->opening_number], [$line->quantite, (float) $line->dose, $line->opening_number]);

        $stock = $this->cancel($sejour, $prise);

        $this->assertSame([3, 12.0], $this->stock($produit));
        $this->assertSame('counted', $stock['reason']);
    }

    // ————— Moyen 2 : la vraie facture d'un séjour sorti —————

    public function test_un_sejour_sorti_montre_sa_facture_emise_sans_la_prise_non_facturee(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $this->give($sejour, $this->prise($sejour, $produit, 600))->assertOk();
        $apres = $this->prise($sejour, $produit, 780);
        $this->travelTo(now()->addMinutes(5));
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        $facture = Facture::withoutGlobalScopes()->with('lignes')->findOrFail($sejour->refresh()->facture_id);
        $this->give($sejour, $apres, ['time' => 800])->assertOk()->assertJsonPath('unbilled', true);

        $apercu = $this->getJson($this->url($sejour, '/facture'), $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame($facture->numero, $apercu['numero']);
        $this->assertEquals($facture->totalTtc(), $apercu['total_chf']);
        $this->assertSame($facture->lignes->pluck('libelle')->all(), array_column($apercu['lignes'], 'libelle'));
        $lignes = app(FactureSejour::class)->lignes($sejour->refresh());
        $this->assertStringContainsString('2 ml en 1 prise', collect($lignes)->pluck('libelle')->implode(' | '));
    }

    // ————— Faible important : lu sous le verrou de la ligne de stock —————

    public function test_le_numero_de_flacon_se_lit_sur_la_ligne_de_stock(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $a = $this->prise($sejour, $produit, 600);
        $this->give($sejour, $a)->assertOk();
        $first = $a->refresh()->opening_number;
        $this->cancel($sejour, $a);
        // Le journal n'est pas lu : même invisible (photo d'avant le verrou), le numéro monte
        MouvementStock::withoutGlobalScopes()->where('produit_id', $produit->id)->delete();

        $b = $this->prise($sejour, $produit, 660);
        $this->give($sejour, $b)->assertOk();

        $this->assertGreaterThan($first, $b->refresh()->opening_number);
        $this->assertSame($b->opening_number, $this->stockRow($produit)->last_opening_number);
    }

    public function test_le_dernier_compte_se_lit_sur_la_ligne_de_stock(): void
    {
        $produit = $this->produit(fermes: 3, entame: 20);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $this->countRest($produit, 17);
        $this->assertNotNull($this->stockRow($produit)->last_counted_at);
        MouvementStock::withoutGlobalScopes()->where('libelle', 'Inventaire du flacon entamé')->delete();

        $this->assertSame('counted', $this->cancel($sejour, $prise)['reason']);
        $this->assertSame([3, 17.0], $this->stock($produit));
    }

    public function test_la_contenance_figee_sur_la_prise_est_celle_vraiment_utilisee(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        // Dixième vérification : l'article se verrouille avant le stock ; la contenance change
        // donc avant ce verrou (après la lecture de la prise), et c'est la nouvelle qui sert
        $isChanged = false;
        DB::listen(function ($query) use ($produit, &$isChanged): void {
            if (! $isChanged && str_contains($query->sql, 'from "produits"')) {
                $isChanged = true;
                DB::table('produits')->where('id', $produit->id)->update(['content_per_unit' => 10]);
            }
        });

        $this->give($sejour, $prise)->assertOk();

        $this->assertEquals(10, $prise->refresh()->content_per_unit);
    }

    public function test_l_annulation_verrouille_le_stock_avant_le_journal(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $order = [];
        DB::listen(function ($query) use (&$order): void {
            foreach (['stocks', 'mouvements_stock'] as $table) {
                if (preg_match('/^select .* from "'.$table.'"/', $query->sql)) {
                    $order[] = $table;
                }
            }
        });

        $this->cancel($sejour, $prise);

        $this->assertSame('stocks', $order[0] ?? null);
    }

    public function test_la_migration_reprend_le_numero_et_le_dernier_compte_depuis_le_journal(): void
    {
        $produit = $this->produit(fermes: 3, entame: 20);
        $sejour = $this->sejour();
        $this->give($sejour, $this->prise($sejour, $produit, 600, 22))->assertOk();
        $this->countRest($produit, 17);
        $this->stockRow($produit)->forceFill(['last_opening_number' => 0, 'last_count_id' => null, 'last_counted_at' => null])->save();

        (require database_path('migrations/2026_11_03_000072_stock_last_opening_and_count.php'))->up();

        $row = $this->stockRow($produit);
        $this->assertSame($row->opening_number, $row->last_opening_number);
        $this->assertSame(MouvementStock::withoutGlobalScopes()->where('libelle', 'Inventaire du flacon entamé')->value('id'), $row->last_count_id);
    }
}
