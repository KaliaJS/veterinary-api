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
use App\Services\GestionnaireStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Hospitalisation, dixième vérification (03.11.2026) : contenance, unité et
 * prix lus sous le verrou ; facture d'une sortie jamais supprimée ; l'article
 * verrouillé avant son stock, partout ; index du journal ; compte vérifié
 * sous le verrou.
 */
class RoundTenthCheckTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    /** @var list<string> Les verrous pris, dans l'ordre (sonde du test de l'ordre des verrous) */
    private array $locks = [];

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

    /** Change l'article en base au premier « select » sur la table donnée (après le chargement de la prise) */
    private function changeProductAt(Produit $produit, array $values, string $table = 'produits'): void
    {
        $isDone = false;
        DB::listen(function ($query) use ($produit, $values, $table, &$isDone): void {
            if (! $isDone && preg_match('/^select .* from "'.$table.'"/', $query->sql)) {
                $isDone = true;
                DB::table('produits')->where('id', $produit->id)->update($values);
            }
        });
    }

    // ————— Faible 1 : la contenance de l'annulation lue sous le verrou —————

    public function test_l_annulation_lit_la_contenance_sous_le_verrou(): void
    {
        $produit = $this->produit(fermes: 3, entame: 12);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, dose: 4);
        $this->give($sejour, $prise)->assertOk();
        $this->assertSame([3, 8.0], $this->stock($produit));
        $this->changeProductAt($produit, ['content_per_unit' => 10]);

        $this->cancel($sejour, $prise);

        $this->assertSame([3, 10.0], $this->stock($produit));
    }

    // ————— Faible 2 : unité et prix figés, ceux qui ont vraiment servi —————

    public function test_l_unite_et_le_prix_figes_sont_lus_sous_le_verrou(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->changeProductAt($produit, ['prix' => 99, 'dose_unit' => 'mg']);

        $this->give($sejour, $prise)->assertOk();

        $prise->refresh();
        $this->assertEquals(99, $prise->unit_price);
        $this->assertSame('mg', $prise->dose_unit);
        $this->assertSame('mg', MouvementStock::withoutGlobalScopes()->where('source_id', $prise->id)->sole()->dose_unit);
    }

    // ————— Faible 3 : la facture d'une sortie ne se supprime pas —————

    public function test_la_facture_d_une_sortie_ne_se_supprime_pas(): void
    {
        $sejour = $this->sejour();
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        $id = $sejour->refresh()->facture_id;

        $this->deleteJson("/api/v1/facturation/factures/{$id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.facture.0', 'Cette facture vient de la sortie d’un séjour : elle ne se supprime pas, ses lignes se corrigent en caisse.');
        $this->assertNotNull(Facture::withoutGlobalScopes()->find($id));
    }

    // ————— Faibles 4 et 5 : l'article d'abord, puis le stock —————

    /** @return list<string> */
    private function locksTaken(): array
    {
        return $this->locks;
    }

    public function test_l_article_se_verrouille_avant_le_stock_dans_chaque_chemin(): void
    {
        // Sonde : chaque verrou pris par le gestionnaire de stock, dans l'ordre
        $record = function (string $lock): void {
            $this->locks[] = $lock;
        };
        $this->app->instance(GestionnaireStock::class, new class($record) extends GestionnaireStock
        {
            public function __construct(private \Closure $record) {}

            protected function lockedProduct(Produit $produit): Produit
            {
                ($this->record)('produit');

                return parent::lockedProduct($produit);
            }

            protected function lockedStocks(Produit $produit): EloquentCollection
            {
                ($this->record)('stock');

                return parent::lockedStocks($produit);
            }

            protected function lockedStock(Produit $produit, int $cliniqueId, bool $isCreating = true): ?Stock
            {
                ($this->record)('stock');

                return parent::lockedStock($produit, $cliniqueId, $isCreating);
            }
        });
        $produit = $this->produit(fermes: 3, entame: 20);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $headers = $this->enTetes($this->jeton);
        $paths = [
            'donner' => fn () => $this->give($sejour, $prise)->assertOk(),
            'compter' => fn () => $this->patchJson("/api/v1/stocks/produits/{$produit->id}/entame", ['opened_rest' => 17, 'dose_unit' => 'ml'], $headers)->assertOk(),
            'annuler' => fn () => $this->cancel($sejour, $prise),
            'contenance' => fn () => $this->patchJson("/api/v1/stocks/produits/{$produit->id}/contenance", ['content_per_unit' => 10, 'dose_unit' => 'ml'], $headers)->assertOk(),
            'jeter' => fn () => $this->postJson("/api/v1/stocks/produits/{$produit->id}/entame/jeter", ['motif' => 'casse'], $headers)->assertOk(),
            // Vérification finale : la caisse, les réceptions et les ordonnances passent par `appliquer`,
            // qui doit prendre le même ordre (l'article, puis le stock) pour ne jamais buter contre une prise
            'appliquer' => fn () => app(GestionnaireStock::class)->sortir($produit, 1, 'Vente au comptoir', null, null, $sejour->clinique_id),
        ];

        foreach ($paths as $name => $call) {
            $this->locks = [];
            $call();
            $order = $this->locksTaken();
            $this->assertSame('produit', $order[0] ?? null, $name);
            $this->assertContains('stock', $order, $name);
            $this->assertNotContains('produit', array_slice($order, (int) array_search('stock', $order, true)), $name);
        }
    }

    // ————— Faible 6 : l'index du journal —————

    public function test_le_journal_a_un_index_produit_clinique_flacon(): void
    {
        $columns = collect(Schema::getIndexes('mouvements_stock'))->pluck('columns')->all();

        $this->assertContains(['produit_id', 'clinique_id', 'opening_number'], $columns);
    }

    // ————— Faible 7 : le compte vérifié sous le verrou —————

    public function test_le_compte_verifie_reste_maximal_et_unite_sous_le_verrou(): void
    {
        $produit = $this->produit(fermes: 3, entame: 20);
        // Lu au chargement de la route, changé avant le verrou
        $this->changeProductAt($produit, ['content_per_unit' => 10]);

        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/entame", ['opened_rest' => 15, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('opened_rest');
        $this->assertSame([3, 20.0], $this->stock($produit));
    }
}
