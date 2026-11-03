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
use App\Models\StaySheet;
use App\Models\StayTreatment;
use App\Models\Stock;
use App\Services\Hospitalisation\CareGiving;
use App\Services\Hospitalisation\DailyReports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Hospitalisation, quatrième vérification (03.11.2026) : l'article servi
 * avec les stocks de toutes les cliniques, les numéros de téléphone
 * courants masqués avant l'IA (et rien d'autre), « donner » sous verrou
 * pendant la sortie, l'heure réelle d'une prise donnée en avance, jeter
 * sans créer de stock, la dose rendue sans flacon entamé, l'inventaire du
 * flacon entamé, le compte rendu vrai de l'annulation, des ajouts sans
 * répétition, le traitement arrêté. Il est 14:05 le mardi 3 novembre 2026.
 */
class RoundFourthCheckTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private string $jeton;

    private Patient $chien;

    private Client $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:05', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Rachel Solal']);
        $this->proprietaire = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->chien = Patient::factory()->for($this->proprietaire)->create(['espece' => Espece::Chien, 'nom' => 'Tao']);
    }

    private function sejour(): Sejour
    {
        return Sejour::factory()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->chien->id, 'client_id' => $this->proprietaire->id,
            'box' => 2, 'statut' => 'surv', 'perfusion' => null,
            'admis_le' => CarbonImmutable::parse('2026-11-01 09:15', 'Europe/Zurich')->utc(),
        ]);
    }

    private function url(Sejour $sejour, string $suite = ''): string
    {
        return "/api/v1/hospitalisation/sejours/{$sejour->id}{$suite}";
    }

    private function produit(int $fermes = 3, float $entame = 0, array $plus = []): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Cerenia 10 mg/ml · 20 ml', 'categorie' => 'med',
            'unite' => 'flacon', 'prix' => 60, 'est_stupefiant' => false, 'dose_unit' => 'ml', 'content_per_unit' => 20, 'seuil_alerte' => 2, ...$plus,
        ]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $fermes, 'opened_rest' => $entame]));

        return $produit;
    }

    private function stock(Produit $produit): array
    {
        $stock = Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->sole();

        return [$stock->quantite, (float) $stock->opened_rest];
    }

    private function prise(Sejour $sejour, ?Produit $produit, string $jour = '2026-11-03', int $minute = 840): SejourSoin
    {
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'dose' => '1 mg/kg IV', 'dose_amount' => $produit ? 2 : null, 'product_id' => $produit?->id, 'times' => [$minute], 'starts_at' => $sejour->admis_le]);

        return SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => $jour, 'prevu_a' => $minute, 'acte' => 'Maropitant', 'dose_amount' => $produit ? 2 : null, 'produit_id' => $produit?->id]);
    }

    // ————— N1 : l'article servi avec les stocks de toutes les cliniques —————

    public function test_contenance_et_flacon_jete_renvoient_les_stocks_de_toutes_les_cliniques(): void
    {
        $produit = $this->produit(fermes: 3, entame: 5);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->soeur->id, 'quantite' => 7]));
        $entetes = $this->enTetes($this->jeton);

        foreach ([
            $this->patchJson("/api/v1/stocks/produits/{$produit->id}/contenance", ['content_per_unit' => 20, 'dose_unit' => 'ml'], $entetes),
            $this->postJson("/api/v1/stocks/produits/{$produit->id}/entame/jeter", ['motif' => 'peremp'], $entetes),
        ] as $reponse) {
            $reponse->assertOk()->assertJsonCount(2, 'data.stocks')->assertJsonPath('data.stock_total', 10)->assertJsonPath('data.sous_seuil', false);
        }
    }

    // ————— N2 : les numéros de téléphone courants, et rien d'autre —————

    public function test_le_masquage_reconnait_les_telephones_courants_et_epargne_le_reste(): void
    {
        $masques = [
            '079/123 45 67', '079-123-45-67', '+41-79-123-45-67', '(079) 123 45 67', 'tél:0791234567', 'tel. 079 123 45 67',
            '+49 151 12345678', '0800 123 456', '0800 80 80 80', '+41 79 123 45 67', '0041 21 555 66 77', '+33 6 12 34 56 78',
            '0033 1 23 45 67 89', '079 123 45 67', '06 12 34 56 78', '0612345678', '01.23.45.67.89', '+44 20 7946 0958',
            '+1 415 555 2671', 'natel 0791234567', 'joignable au 021 555 66 77', 'appeler le 0215556677', '+41 (0)79 123 45 67',
        ];
        foreach ($masques as $texte) {
            $this->assertStringNotContainsString(preg_replace('/\D/', '', substr($texte, -6)), DailyReports::withoutContacts($texte), $texte);
            $this->assertStringContainsString('[téléphone]', DailyReports::withoutContacts($texte), $texte);
        }

        // Cinquième vérification : aucun mot ne décide plus. Ce qui a la forme forte
        // d'un numéro est masqué même après « Lot », « FC » ou « Glycémie » :
        // faux positifs acceptés
        foreach (['Lot 0123456789', 'prises à 06 08 10 12 14 h', 'Dates 03 11 26 04 11 26', 'Glycémie 06 05 04 03 02', 'FC 090 120 45 67', 'lot 0791234567'] as $texte) {
            $this->assertStringContainsString('[téléphone]', DailyReports::withoutContacts($texte), $texte);
        }
        $intacts = [
            '10 000 000 UI', '0.5 - 1 - 1.5 ml', '8 12 16 20 24 h', '3.11.26 - 5.11.26', '120 mg à 08:00', 'T° 38,5 °C',
            'FR 24, FC 112', '1 mg/kg IV', '0,2 ml par prise', 'Jour 3 du séjour', 'le 03.11.2026', '2026-11-03', 'à 14:05',
            'NaCl 0,9 % · 80 ml/h', 'Insuline 0,5 UI/kg SC', 'Doses 0,1 0,2 0,3', 'Poids 12,450 kg',
        ];
        foreach ($intacts as $texte) {
            $this->assertSame($texte, DailyReports::withoutContacts($texte), $texte);
        }
        $this->assertGreaterThanOrEqual(35, count($masques) + count($intacts));
    }

    // ————— N3 : « donner » sous verrou pendant la sortie —————

    public function test_une_prise_donnee_qui_a_lu_le_sejour_avant_la_sortie_est_notee_non_facturee(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        // Sonde : le geste a lu le séjour avant que la sortie passe
        $avantSortie = Sejour::withoutGlobalScopes()->find($sejour->id);
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();

        app(ContexteClinique::class)->definir($this->clinique);
        $resultat = app(CareGiving::class)->give($avantSortie, $prise->id, [], null);

        $this->assertTrue($resultat['unbilled']);
        $this->assertTrue($prise->refresh()->fait);
        $this->assertNotNull($prise->late_noted_at);
        $this->assertSame([2, 18.0], $this->stock($produit));
    }

    // ————— Faibles —————

    public function test_une_prise_donnee_en_avance_garde_l_heure_reelle_tapee_dans_la_fenetre(): void
    {
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, null, '2026-11-04', 390);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 05:50', 'Europe/Zurich'));

        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), ['time' => 240], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), ['time' => 345], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame('2026-11-04 05:45', $prise->refresh()->fait_le->setTimezone('Europe/Zurich')->format('Y-m-d H:i'));
    }

    public function test_un_refus_de_jeter_ne_cree_pas_de_stock(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'categorie' => 'med']);

        $this->postJson("/api/v1/stocks/produits/{$produit->id}/entame/jeter", ['motif' => 'peremp'], $this->enTetes($this->jeton))->assertUnprocessable();

        $this->assertSame(0, Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->count());
    }

    public function test_annuler_apres_avoir_jete_le_flacon_entame_ne_recree_pas_de_reste(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/stocks/produits/{$produit->id}/entame/jeter", ['motif' => 'casse'], $this->enTetes($this->jeton))->assertOk();

        $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('stock.lost', true)->assertJsonPath('stock.returned', false);

        $this->assertSame([2, 0.0], $this->stock($produit));
        $this->assertStringContainsString('non remise en stock', MouvementStock::withoutGlobalScopes()->latest('id')->first()->libelle);
    }

    public function test_l_inventaire_corrige_le_reste_entame_et_la_contenance_le_convertit(): void
    {
        $produit = $this->produit(fermes: 3, entame: 15.7);
        $entetes = $this->enTetes($this->jeton);

        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/entame", ['opened_rest' => 12, 'dose_unit' => 'ml'], $entetes)->assertOk();
        $this->assertSame([3, 12.0], $this->stock($produit));
        $mouvement = MouvementStock::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame([0, -3.7, 'inv'], [$mouvement->quantite, (float) $mouvement->dose, $mouvement->motif->value]);

        // Même unité, contenance plus petite : le reste est ramené à la contenance, avec une trace
        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/contenance", ['content_per_unit' => 10, 'dose_unit' => 'ml'], $entetes)->assertOk();
        $this->assertSame([3, 10.0], $this->stock($produit));
        // Autre unité : le reste ne se convertit pas, il est remis à zéro avec une trace
        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/contenance", ['content_per_unit' => 20, 'dose_unit' => 'cp'], $entetes)->assertOk();
        $this->assertSame([3, 0.0], $this->stock($produit));
        $this->assertStringContainsString('Contenance changée', MouvementStock::withoutGlobalScopes()->latest('id')->first()->libelle);
    }

    public function test_l_annulation_dit_ce_qui_revient_vraiment_au_stock(): void
    {
        $sejour = $this->sejour();
        $sansProduit = $this->prise($sejour, null);
        $sansProduit->forceFill(['fait' => true, 'fait_le' => now()])->save();

        $this->postJson($this->url($sejour, "/soins/{$sansProduit->id}/annuler"), [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('stock', null);

        $produit = $this->produit();
        $prise = $this->prise($sejour, $produit, minute: 900);
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('stock.returned', true)->assertJsonPath('stock.dose', 2)->assertJsonPath('stock.dose_unit', 'ml');
    }

    public function test_les_ajouts_apres_la_signature_ne_se_repetent_pas(): void
    {
        $sejour = $this->sejour();
        StaySheet::create(['sejour_id' => $sejour->id, 'day' => '2026-11-02', 'opened_at' => now(), 'report_status' => 'signed', 'report_text' => 'Signé.', 'report_signed_at' => now()->subHours(10), 'report_signed_by_name' => 'Dr Anna Meyer']);

        $this->postJson($this->url($sejour, '/observations'), ['kind' => 'eats', 'value' => 'rien', 'sheet_day' => '2026-11-02', 'time' => 1170], $this->enTetes($this->jeton))->assertCreated();

        $ajout = $this->getJson($this->url($sejour, '/feuilles/2026-11-02'), $this->enTetes($this->jeton))->json('data.report.late_additions.0');
        $this->assertSame('Mange : rien à 19:30, noté après coup le 3.11 à 14:05 par Dr Rachel Solal', $ajout['label']);
        $this->assertNull($ajout['late_label']);
    }

    public function test_un_traitement_arrete_ne_se_donne_plus_en_avance(): void
    {
        $sejour = $this->sejour();
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'times' => [390], 'starts_at' => $sejour->admis_le]);
        $this->postJson($this->url($sejour, "/traitements/{$traitement->id}/arreter"), [], $this->enTetes($this->jeton))->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 05:50', 'Europe/Zurich'));
        $this->postJson($this->url($sejour, "/traitements/{$traitement->id}/prises"), ['day' => '2026-11-04', 'minute' => 390], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonPath('errors.traitement.0', 'Traitement arrêté : cette prise n’est plus prévue.');
    }
}
