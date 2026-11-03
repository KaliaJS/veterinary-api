<?php

namespace Tests\Feature\Hospitalisation;

use App\Enums\Espece;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\MouvementStock;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayCareCancellation;
use App\Models\StayTreatment;
use App\Models\Stock;
use App\Services\Hospitalisation\StayRound;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Hospitalisation, huitième vérification (03.11.2026) : rien ne revient
 * dans un flacon compté depuis la prise ; un numéro de flacon ne resert
 * jamais ; contenance effacée ; contenance lue sous verrou ; anciennes
 * annulations repérées par l'historique ; facturée ou non, sans ambiguïté.
 */
class RoundEighthCheckTest extends TestCase
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

    private function care(Sejour $sejour, SejourSoin $prise): array
    {
        return app(StayRound::class)->care($sejour->refresh(), $prise->refresh());
    }

    private function migration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    /** Une prise annulée avant 000068 : sa sortie n'est pas marquée rendue, ni son flacon connu du journal */
    private function asBefore068(SejourSoin $prise, bool $isCancelled = true): void
    {
        MouvementStock::withoutGlobalScopes()->where('source_type', $prise->getMorphClass())->where('source_id', $prise->id)
            ->update(['reversed_at' => null, 'opening_number' => null, 'previous_opening_number' => null, 'from_opened' => null]);
        if ($isCancelled) {
            StayCareCancellation::query()->where('sejour_soin_id', $prise->id)->delete();
        }
    }

    private function takeOf(SejourSoin $prise): MouvementStock
    {
        return MouvementStock::withoutGlobalScopes()->where('source_type', $prise->getMorphClass())->where('source_id', $prise->id)
            ->where(fn ($q) => $q->where('dose', '<', 0)->orWhere('quantite', '<', 0))->orderByDesc('id')->firstOrFail();
    }

    // ————— Moyen : un flacon compté depuis la prise ne reprend rien —————

    public function test_inventaire_sans_debordement_puis_annulation_ne_compte_pas_deux_fois(): void
    {
        $produit = $this->produit(fermes: 3, entame: 20);
        $sejour = $this->sejour();
        $a = $this->prise($sejour, $produit, 840);
        $b = $this->prise($sejour, $produit, 900);
        $this->give($sejour, $a)->assertOk();
        $this->give($sejour, $b)->assertOk();
        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/entame", ['opened_rest' => 18, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertOk();

        $stock = $this->cancel($sejour, $a);

        $this->assertSame([3, 18.0], $this->stock($produit));
        $this->assertSame('counted', $stock['reason']);
        $this->assertEquals(2, $stock['surplus']);
        $count = MouvementStock::withoutGlobalScopes()->where('libelle', 'Inventaire du flacon entamé')->sole();
        $this->assertSame($a->refresh()->opening_number, $count->opening_number);
    }

    public function test_un_numero_de_flacon_ne_resert_jamais(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $a = $this->prise($sejour, $produit, 600);
        $this->give($sejour, $a)->assertOk();
        $first = $a->refresh()->opening_number;
        $this->cancel($sejour, $a);
        $this->assertSame([3, 0.0], $this->stock($produit));

        $b = $this->prise($sejour, $produit, 660);
        $this->give($sejour, $b)->assertOk();
        $this->assertGreaterThan($first, $b->refresh()->opening_number);
        $this->postJson("/api/v1/stocks/produits/{$produit->id}/entame/jeter", ['motif' => 'casse'], $this->enTetes($this->jeton))->assertOk();
        $c = $this->prise($sejour, $produit, 720);
        $this->give($sejour, $c)->assertOk();
        $this->assertNotContains($c->refresh()->opening_number, [$first, $b->opening_number]);

        $this->cancel($sejour, $b);
        $this->assertSame([1, 18.0], $this->stock($produit));
    }

    // ————— Faibles —————

    public function test_contenance_effacee_la_sortie_est_traitee_et_la_perte_notee(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $produit->forceFill(['content_per_unit' => null])->save();

        $stock = $this->cancel($sejour, $prise);

        $this->assertNotNull($this->takeOf($prise)->reversed_at);
        $this->assertSame('content', $stock['reason']);
        $this->assertStringContainsString('perdus', MouvementStock::withoutGlobalScopes()->latest('id')->first()->libelle);
    }

    public function test_la_contenance_se_lit_apres_le_verrou_du_stock(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        // Sonde : la contenance change juste quand la prise verrouille le stock
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

        $this->assertTrue($isChanged);
        $this->assertSame([2, 8.0], $this->stock($produit));
    }

    public function test_000070_repere_les_anciennes_annulations_par_l_historique(): void
    {
        $produit = $this->produit(fermes: 3, unite: 'pièce');
        $sejour = $this->sejour();
        $soin = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 780, 'acte' => 'Pansement', 'produit_id' => $produit->id]);
        $this->give($sejour, $soin)->assertOk();
        $this->cancel($sejour, $soin);
        // Avant le suivi : la sortie n'est pas marquée, et le retour ne dit pas « Prise annulée »
        $this->takeOf($soin)->forceFill(['reversed_at' => null])->save();
        MouvementStock::withoutGlobalScopes()->where('source_id', $soin->id)->where('quantite', '>', 0)->update(['libelle' => 'Retour au stock']);

        $this->migration('2026_11_03_000070_stay_care_legacy_moves');

        $this->assertNotNull($this->takeOf($soin)->reversed_at);
    }

    public function test_une_prise_d_avant_le_suivi_comptee_depuis_dit_unknown(): void
    {
        $produit = $this->produit(fermes: 3, entame: 20);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $prise->forceFill(['opening_number' => null])->save();
        $this->asBefore068($prise, isCancelled: false);
        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/entame", ['opened_rest' => 17, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame('unknown', $this->cancel($sejour, $prise)['reason']);
    }

    public function test_une_prise_facturee_notee_dans_la_seconde_de_la_sortie_ne_s_annule_pas(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        // Notée après coup dans la même seconde que la sortie, avant elle : facturée
        $prise->forceFill(['late_noted_at' => $sejour->refresh()->sorti_le])->save();

        $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertNull($this->care($sejour, $prise)['unbilled_label']);
    }
}
