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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Hospitalisation, septième vérification (03.11.2026) : une prise annulée
 * notée de nouveau garde la dose tapée ; les données d'avant les
 * migrations 000067 et 000068 (sorties déjà rendues, numéro de flacon) ;
 * 000069 rejouée ; inventaire puis annulation ; unité de l'historique ;
 * flacon remis à zéro par un changement d'unité.
 */
class RoundSeventhCheckTest extends TestCase
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

    // ————— M1 : la dose tapée d'une prise annulée notée de nouveau —————

    public function test_une_prise_annulee_notee_de_nouveau_garde_la_dose_envoyee_sinon_la_prescrite(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, dose: 2);
        $this->give($sejour, $prise, ['dose_amount' => 3])->assertOk();
        $this->cancel($sejour, $prise);

        $this->give($sejour, $prise, ['dose_amount' => 3])->assertOk();
        $this->assertEquals(3, $prise->refresh()->dose_amount);
        $this->assertSame([2, 17.0], $this->stock($produit));

        $this->cancel($sejour, $prise);
        $this->give($sejour, $prise)->assertOk();
        $this->assertEquals(2, $prise->refresh()->dose_amount);
    }

    // ————— L1 : sorties des prises annulées avant 000068 —————

    public function test_un_pansement_annule_avant_000068_n_est_pas_rendu_deux_fois(): void
    {
        $produit = $this->produit(fermes: 3, unite: 'pièce');
        $sejour = $this->sejour();
        $soin = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 780, 'acte' => 'Pansement', 'produit_id' => $produit->id]);
        $this->give($sejour, $soin)->assertOk();
        $this->cancel($sejour, $soin);
        $this->assertSame(3, $this->stock($produit)[0]);
        $this->asBefore068($soin);

        $this->migration('2026_11_03_000069_stay_care_cancellations_backfill');
        $this->migration('2026_11_03_000070_stay_care_legacy_moves');
        $this->migration('2026_11_03_000070_stay_care_legacy_moves');
        $this->give($sejour, $soin)->assertOk();
        $this->assertSame(2, $this->stock($produit)[0]);
        $this->cancel($sejour, $soin);

        $this->assertSame(3, $this->stock($produit)[0]);
    }

    // ————— L2 : le numéro de flacon d'une sortie d'avant 000068 —————

    public function test_une_sortie_d_avant_000068_retrouve_son_flacon_par_la_prise(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $this->assertNotNull($prise->refresh()->opening_number);
        $this->asBefore068($prise, isCancelled: false);

        $this->migration('2026_11_03_000070_stay_care_legacy_moves');
        $this->cancel($sejour, $prise);

        // Reconnue comme venant du flacon encore ouvert : il redevient fermé, comme sans migration
        $this->assertSame([3, 0.0], $this->stock($produit));
    }

    public function test_sans_numero_de_flacon_le_motif_ne_dit_pas_fini(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $prise->forceFill(['opening_number' => null])->save();
        $this->asBefore068($prise, isCancelled: false);

        $stock = $this->cancel($sejour, $prise);

        $this->assertSame('unknown', $stock['reason']);
        $this->assertStringNotContainsString('fini', MouvementStock::withoutGlobalScopes()->latest('id')->first()->libelle);
    }

    // ————— Faibles —————

    public function test_000069_rejouee_n_ecrit_pas_deux_fois_la_meme_annulation(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $this->cancel($sejour, $prise);

        $this->migration('2026_11_03_000069_stay_care_cancellations_backfill');
        $this->migration('2026_11_03_000069_stay_care_cancellations_backfill');

        $this->assertSame(1, StayCareCancellation::query()->where('sejour_soin_id', $prise->id)->count());
    }

    public function test_inventaire_puis_annulation_dit_ce_qui_revient_vraiment(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/entame", ['opened_rest' => 18.5, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertOk();

        $stock = $this->cancel($sejour, $prise);

        // Huitième vérification : compté depuis la prise, rien ne revient dans ce flacon (il
        // n'est pas recompté fermé) ; la dose est notée perdue, motif « counted »
        $this->assertSame([2, 18.5], $this->stock($produit));
        $this->assertEquals(0, $stock['dose']);
        $this->assertEquals(2, $stock['surplus']);
        $this->assertSame('counted', $stock['reason']);
        $this->assertStringContainsString('compté entre-temps : 2 ml perdus', MouvementStock::withoutGlobalScopes()->latest('id')->first()->libelle);
    }

    public function test_l_historique_prend_l_unite_de_l_article_si_aucune_n_est_figee(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise, ['dose_amount' => 15])->assertOk();
        $this->cancel($sejour, $prise);
        StayCareCancellation::query()->where('sejour_soin_id', $prise->id)->update(['dose_unit' => null]);
        $this->give($sejour, $prise)->assertOk();

        $this->assertStringContainsString('15 ml donnés', (string) $this->care($sejour, $prise)['previous_label']);
    }

    public function test_une_annulation_ne_remet_rien_dans_un_flacon_remis_a_zero_par_l_unite(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $url = "/api/v1/stocks/produits/{$produit->id}/contenance";
        $this->patchJson($url, ['content_per_unit' => 10, 'dose_unit' => 'cp'], $this->enTetes($this->jeton))->assertOk();
        $this->patchJson($url, ['content_per_unit' => 20, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(0.0, $this->stock($produit)[1]);

        $stock = $this->cancel($sejour, $prise);

        $this->assertSame([2, 0.0], $this->stock($produit));
        $this->assertSame('reset', $stock['reason']);
    }
}
