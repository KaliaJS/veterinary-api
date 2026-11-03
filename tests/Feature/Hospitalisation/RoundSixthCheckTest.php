<?php

namespace Tests\Feature\Hospitalisation;

use App\Auth\ContexteClinique;
use App\Enums\Espece;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\MouvementStock;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use App\Models\Stock;
use App\Services\Hospitalisation\CareGiving;
use App\Services\Hospitalisation\DailyReports;
use App\Services\Hospitalisation\StayRound;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Hospitalisation, sixième vérification (03.11.2026) : une annulation rend
 * exactement ce que la prise a sorti, unités fermées comprises ; une dose
 * plus grande que le stock disponible est refusée ; une prise annulée se
 * note de nouveau ; espaces insécables ; contenance la plus petite ; unité
 * figée partout ; motifs justes ; rapport IA et résumé ; prise « raced » ;
 * soin infirmier après la sortie. Il est 14:05 le mardi 3 novembre 2026.
 */
class RoundSixthCheckTest extends TestCase
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

    // ————— G1 : l'annulation rend exactement ce que la prise a sorti —————

    public function test_une_dose_qui_ouvre_vingt_flacons_les_rend_tous_a_l_annulation(): void
    {
        $produit = $this->produit(fermes: 20, entame: 6);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, dose: 5);
        $this->give($sejour, $prise, ['dose_amount' => 406])->assertOk();
        $this->assertSame([0, 0.0], $this->stock($produit));

        $stock = $this->cancel($sejour, $prise);

        $this->assertSame([20, 6.0], $this->stock($produit));
        $this->assertFalse($stock['lost']);
        $this->assertEquals(406, $stock['dose']);
    }

    public function test_une_ampoule_ouverte_et_videe_par_la_prise_redevient_fermee(): void
    {
        $produit = $this->produit(fermes: 3, contenance: 2, unite: 'ampoule');
        $sejour = $this->sejour();
        foreach ([840, 900] as $minute) {
            $prise = $this->prise($sejour, $produit, $minute, 2);
            $this->give($sejour, $prise)->assertOk();
            $this->assertSame([2, 0.0], $this->stock($produit));
            $this->cancel($sejour, $prise);
            $this->assertSame([3, 0.0], $this->stock($produit));
        }
    }

    public function test_une_dose_qui_a_fini_le_flacon_revient(): void
    {
        $produit = $this->produit(fermes: 3, entame: 2);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $this->assertSame([3, 0.0], $this->stock($produit));

        $this->cancel($sejour, $prise);

        $this->assertSame([3, 2.0], $this->stock($produit));
    }

    public function test_une_dose_plus_grande_que_le_stock_disponible_est_refusee(): void
    {
        $produit = $this->produit(fermes: 20, entame: 6);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, dose: 5);

        $this->give($sejour, $prise, ['dose_amount' => 500])->assertUnprocessable()
            ->assertJsonValidationErrors('dose_amount')
            ->assertJsonPath('errors.dose_amount.0', 'Cette dose (500 ml) dépasse le stock disponible (406 ml) : vérifiez la dose.');
        $this->assertSame([20, 6.0], $this->stock($produit));
        $this->assertFalse($prise->refresh()->fait);
    }

    public function test_la_dose_prescrite_en_rupture_partielle_dit_ce_qui_est_sorti(): void
    {
        $produit = $this->produit(fermes: 0, entame: 1);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);

        $this->give($sejour, $prise)->assertOk()
            ->assertJsonPath('stock.rupture', true)->assertJsonPath('stock.dose', 1)->assertJsonPath('stock.dose_requested', 2);
    }

    // ————— M1 : une prise annulée se note de nouveau —————

    public function test_une_prise_annulee_se_note_de_nouveau_a_la_bonne_dose(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, dose: 1.5);
        $this->give($sejour, $prise, ['dose_amount' => 15])->assertOk();
        $this->cancel($sejour, $prise);
        $this->assertSame('cancelled', $this->care($sejour, $prise)['status']);

        // La dose prescrite revient d'elle-même
        $this->give($sejour, $prise)->assertOk();

        $this->assertSame([2, 18.5], $this->stock($produit));
        $care = $this->care($sejour, $prise);
        $this->assertSame('done', $care['status']);
        $this->assertEquals(1.5, $care['dose_amount']);
        $this->assertNull($care['cancelled_label']);
        $this->assertStringContainsString('15 ml donnés à 14:05 par Dr Rachel Solal, annulés par Dr Rachel Solal à 14:05', (string) $care['previous_label']);
        $lignes = collect($this->getJson($this->url($sejour, '/facture'), $this->enTetes($this->jeton))->json('data.lignes'));
        $this->assertNotNull($lignes->first(fn (array $l) => str_contains($l['libelle'], '1,5 ml en 1 prise')));
        $facts = app(DailyReports::class)->facts($sejour->refresh(), '2026-11-03');
        $this->assertStringContainsString('Maropitant, 1,5 ml, donné', $facts);
        $this->assertStringContainsString('annulée', $facts);
    }

    public function test_une_prise_prevue_annulee_se_redonne_par_les_prises_du_traitement(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, 900);
        $this->give($sejour, $prise)->assertOk();
        $this->cancel($sejour, $prise);

        $this->postJson($this->url($sejour, "/traitements/{$prise->treatment_id}/prises"), ['day' => '2026-11-03', 'minute' => 900], $this->enTetes($this->jeton))->assertOk();

        $this->assertTrue($prise->refresh()->fait);
        $this->assertNull($prise->cancelled_at);
    }

    // ————— M2 : espaces insécables —————

    public function test_les_espaces_insecables_et_fines_separent_aussi(): void
    {
        foreach (["079\u{00A0}123\u{00A0}45\u{00A0}67", "079\u{202F}123\u{202F}45\u{202F}67", "079\u{2009}123\u{2009}45\u{2009}67", "+41\u{00A0}79\u{202F}123\u{2009}45\u{00A0}67", "021\u{00A0}555\u{00A0}66\u{00A0}77"] as $number) {
            $this->assertSame('tél [téléphone]', DailyReports::withoutContacts('tél '.$number), $number);
        }
        // Un numéro connu écrit avec des espaces insécables, et rangé ainsi
        $this->assertSame('[téléphone]', DailyReports::withoutContacts("21\u{202F}555\u{202F}66\u{202F}77", ["032\u{00A0}444 11 22", "021\u{00A0}555\u{00A0}66\u{00A0}77"]));
    }

    // ————— M3 : la plus petite contenance —————

    public function test_l_annulation_ne_depasse_pas_la_contenance_actuelle(): void
    {
        $produit = $this->produit(fermes: 3, entame: 15);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/contenance", ['content_per_unit' => 10, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(10.0, $this->stock($produit)[1]);

        $stock = $this->cancel($sejour, $prise);

        $this->assertSame([3, 10.0], $this->stock($produit));
        $this->assertEquals(2, $stock['surplus']);
    }

    // ————— Faibles —————

    public function test_l_unite_affichee_est_celle_figee_sur_la_prise(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise)->assertOk();
        $produit->forceFill(['dose_unit' => 'cp'])->save();

        $this->assertSame('ml', $this->care($sejour, $prise)['dose_unit']);
        $lignes = collect($this->getJson($this->url($sejour, '/facture'), $this->enTetes($this->jeton))->json('data.lignes'));
        $this->assertNotNull($lignes->first(fn (array $l) => str_contains($l['libelle'], '2 ml en 1 prise')));
        $this->getJson("/api/v1/stocks/produits/{$produit->id}/mouvements", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.0.dose_unit', 'ml');
    }

    public function test_une_prise_notee_apres_la_sortie_non_facturee_s_annule(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $avant = $this->prise($sejour, $produit, 600);
        $apres = $this->prise($sejour, $produit, 780);
        $this->give($sejour, $avant)->assertOk();
        $this->travelTo(now()->addMinutes(5));
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        $this->give($sejour, $apres, ['time' => 800])->assertOk()->assertJsonPath('unbilled', true);

        $this->cancel($sejour, $apres);
        $this->assertSame([2, 18.0], $this->stock($produit));
        // Une prise facturée ne s'annule plus ici
        $this->postJson($this->url($sejour, "/soins/{$avant->id}/annuler"), [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_le_motif_dit_jete_ou_fini(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $jete = $this->prise($sejour, $produit, 600);
        $this->give($sejour, $jete)->assertOk();
        $this->postJson("/api/v1/stocks/produits/{$produit->id}/entame/jeter", ['motif' => 'casse'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame('discarded', $this->cancel($sejour, $jete)['reason']);

        $fini = $this->prise($sejour, $produit, 660);
        $this->give($sejour, $fini)->assertOk();
        $this->give($sejour, $this->prise($sejour, $produit, 700, 18))->assertOk();
        $this->give($sejour, $this->prise($sejour, $produit, 720))->assertOk();
        $stock = $this->cancel($sejour, $fini);

        $this->assertSame('finished', $stock['reason']);
        $this->assertStringNotContainsString('jeté', MouvementStock::withoutGlobalScopes()->latest('id')->first()->libelle);
    }

    public function test_le_rapport_ia_dit_annulee_et_le_resume_ne_la_compte_pas(): void
    {
        $sejour = $this->sejour();
        $annulee = $this->prise($sejour, null, 600);
        $this->give($sejour, $annulee)->assertOk();
        $this->cancel($sejour, $annulee);
        $this->give($sejour, $this->prise($sejour, null, 660))->assertOk();

        $facts = app(DailyReports::class)->facts($sejour->refresh(), '2026-11-03');
        $line = collect(explode("\n", $facts))->first(fn (string $l) => str_contains($l, 'prévu à 10:00'));
        $this->assertStringContainsString('annulée', $line);
        $this->assertStringNotContainsString('pas noté', $line);
        $summary = collect($this->getJson($this->url($sejour, '/jours'), $this->enTetes($this->jeton))->json('data.days'))->firstWhere('day', '2026-11-03')['summary'];
        $this->assertStringContainsString('1 médicament sur 1', $summary);
    }

    public function test_une_prise_raced_ne_tombe_pas_apres_la_sortie(): void
    {
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, null, 840);
        $avantSortie = Sejour::withoutGlobalScopes()->find($sejour->id);
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        $this->travelTo(now()->addMinutes(3));
        app(ContexteClinique::class)->definir($this->clinique);

        app(CareGiving::class)->give($avantSortie, $prise->id, [], null);

        $this->assertTrue($prise->refresh()->fait_le->lte($sejour->refresh()->sorti_le));
    }

    public function test_un_soin_infirmier_note_apres_la_sortie_le_dit(): void
    {
        $sejour = $this->sejour();
        $soin = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 780, 'acte' => 'Pansement']);
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        $this->give($sejour, $soin, ['time' => 800])->assertOk();

        $this->assertSame('Facture déjà émise : ce soin n’est pas facturé', $this->care($sejour, $soin)['unbilled_label']);
    }
}
