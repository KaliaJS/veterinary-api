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
use App\Services\Hospitalisation\DailyReportWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\DailyReportWriterFake;
use Tests\TestCase;

/**
 * Hospitalisation, cinquième vérification (03.11.2026) : le masquage des
 * numéros change d'approche (formes fortes toujours masquées, numéros
 * connus du séjour reconnus sous toutes leurs formes, le reste intact) ;
 * une prise sait de quel flacon entamé elle vient, un flacon entamé n'est
 * jamais compté fermé ; l'unité figée sur la prise ; une annulation ne
 * dépasse pas la contenance ; « donné en avance » après la sortie ; le
 * motif d'une prise non facturée ; « compter le reste » dans l'unité de
 * l'article. Il est 14:05 le mardi 3 novembre 2026 à la clinique.
 */
class RoundFifthCheckTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    /** Le propriétaire de l'admission et son contact d'urgence, connus du séjour */
    private const string OWNER = '021 555 66 77';

    private const string EMERGENCY = '032 444 11 22';

    /** 33 numéros : formes fortes, et numéros connus écrits autrement */
    private const array NUMBERS = [
        '+41 79 123 45 67', '+41-79-123-45-67', '+41/79/123.45.67', '+41 (0)79 123 45 67', '0041 79 123 45 67', '0041791234567',
        '+33 6 12 34 56 78', '+33 (0)6 12 34 56 78', '0033 7 12 34 56 78', '+49 151 12345678', '+44 20 7946 0958', '+1 (415) 555-2671',
        '079 123 45 67', '079/123 45 67', '079-123-45-67', '0791234567', '076.234.56.78', '078 9876543',
        '06 12 34 56 78', '07.12.34.56.78', '0612345678', '0800 80 80 80', '0848 123 456', '0900 575 775', '0901 123456',
        '044 123 45 67', '0441234567', '021.555.66.77', '0041215556677', '+41 (0)21 555 66 77', '0215556677', '032-444-11-22', '+41324441122',
    ];

    /** 15 phrases, avec les mots qui trompaient l'ancienne approche */
    private const array SENTENCES = [
        'appeler le propriétaire, poids {n}', 'Lot {n}', 'FC {n} au réveil', 'Glycémie {n}', 'prises à {n}', 'Dates {n}', '{n}',
        'joignable au {n}.', 'Contact : {n}, merci', 'Note ({n})', 'tél:{n}', 'poids 12,4 kg, puis {n}', 'T° 38,5 puis {n}',
        'rappel{n}demain', 'voir lot {n} et FC 120',
    ];

    /** 45 textes cliniques : rien à masquer */
    private const array CLINICAL = [
        '10 000 000 UI', '0.5 - 1 - 1.5 ml', '8 12 16 20 24 h', '3.11.26 - 5.11.26', '120 mg à 08:00', 'T° 38,5 °C', 'FR 24, FC 112',
        '1 mg/kg IV', '0,2 ml par prise', 'Jour 3 du séjour', 'le 03.11.2026', '2026-11-03', 'à 14:05', 'NaCl 0,9 % · 80 ml/h',
        'Insuline 0,5 UI/kg SC', 'Doses 0,1 0,2 0,3', 'Poids 12,450 kg', 'PA 120/80 mmHg', 'Hct 45 %', 'Créatinine 245 µmol/l',
        'Urée 18,5 mmol/l', 'Glucose 6,8 mmol/l', 'Lactates 2,1', 'Prises à 08:00, 14:00, 20:00', 'Box 02, jour 4',
        'Perfusion 60 ml/h de 08:00 à 20:00', 'Méloxicam 0,05 mg/kg PO 1 x/j', 'Amoxicilline 12,5 mg/kg 2 x/j', 'Lot 4521-B, péremption 10.2027',
        'Lot AB12345', 'Facture FC-2026-0354', 'Ordonnance RX-2026-0012', 'Douleur 4 sur 24 puis 2 sur 24', 'Repas : 3 fois 50 g',
        'Diurèse 2 ml/kg/h', 'Vomit 2 fois à 09:10 et 11:40', 'Selles molles à 07:30', 'SpO2 97 %', 'FC 88 à 08:00, 92 à 12:00',
        'Contrôle à J+2 et J+7', 'Sortie prévue le 5.11 à 17:00', 'Puce 756 0981 2345 678', 'Température 37,9 puis 38,2', 'Plaquettes 180 G/l',
        'Ringer lactate 500 ml en 4 h', 'le 06.11.2026 08:00', 'du 07.11.2026 10:30 au 08.11.2026 09:00',
    ];

    private Clinique $clinique;

    private string $jeton;

    private Patient $chien;

    private Client $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:05', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Rachel Solal']);
        $this->proprietaire = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'tel' => self::OWNER, 'emergency_phone' => self::EMERGENCY]);
        $this->chien = Patient::factory()->for($this->proprietaire)->create(['espece' => Espece::Chien, 'nom' => 'Tao']);
    }

    private function sejour(array $plus = []): Sejour
    {
        return Sejour::factory()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->chien->id, 'client_id' => $this->proprietaire->id,
            'box' => 2, 'statut' => 'surv', 'perfusion' => null,
            'admis_le' => CarbonImmutable::parse('2026-11-01 09:15', 'Europe/Zurich')->utc(), ...$plus,
        ]);
    }

    private function url(Sejour $sejour, string $suite = ''): string
    {
        return "/api/v1/hospitalisation/sejours/{$sejour->id}{$suite}";
    }

    private function produit(int $fermes = 3, float $entame = 0): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Cerenia 10 mg/ml · 20 ml', 'categorie' => 'med',
            'unite' => 'flacon', 'prix' => 60, 'est_stupefiant' => false, 'dose_unit' => 'ml', 'content_per_unit' => 20,
        ]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $fermes, 'opened_rest' => $entame, 'opened_at' => $entame > 0 ? now()->subDay() : null]));

        return $produit;
    }

    private function stock(Produit $produit): array
    {
        $stock = Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();

        return [$stock->quantite, (float) $stock->opened_rest];
    }

    private function prise(Sejour $sejour, Produit $produit, int $minute = 840): SejourSoin
    {
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'dose_amount' => 2, 'product_id' => $produit->id, 'times' => [$minute], 'starts_at' => $sejour->admis_le]);

        return SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-03', 'prevu_a' => $minute, 'acte' => 'Maropitant', 'dose_amount' => 2, 'produit_id' => $produit->id]);
    }

    private function give(Sejour $sejour, SejourSoin $prise, array $data = []): void
    {
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), $data, $this->enTetes($this->jeton))->assertOk();
    }

    private function cancel(Sejour $sejour, SejourSoin $prise): array
    {
        return $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))->assertOk()->json('stock') ?? [];
    }

    // ————— N2 : formes fortes, numéros connus, le reste intact —————

    public function test_aucune_fuite_sur_495_phrases_de_33_numeros(): void
    {
        $this->assertCount(33, self::NUMBERS);
        $known = [self::OWNER, self::EMERGENCY];
        $leaks = [];
        foreach (self::NUMBERS as $number) {
            $tail = substr((string) preg_replace('/\D/', '', $number), -7);
            foreach (self::SENTENCES as $sentence) {
                $text = str_replace('{n}', $number, $sentence);
                $masked = DailyReports::withoutContacts($text, $known);
                if (str_contains((string) preg_replace('/\D/', '', $masked), $tail)) {
                    $leaks[] = $text.' → '.$masked;
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_les_45_textes_cliniques_restent_intacts(): void
    {
        $this->assertGreaterThanOrEqual(45, count(self::CLINICAL));
        foreach (self::CLINICAL as $text) {
            $this->assertSame($text, DailyReports::withoutContacts($text, [self::OWNER, self::EMERGENCY]), $text);
        }
        // Les e-mails restent masqués
        $this->assertSame('écrire à [e-mail]', DailyReports::withoutContacts('écrire à leroy@example.ch'));
    }

    public function test_les_numeros_connus_du_sejour_sont_masques_dans_ce_qui_part_a_l_ia(): void
    {
        $sejour = $this->sejour(['note' => 'Propriétaire : 21 555 66 77 (sans le zéro), urgence 0324441122']);
        $writer = new DailyReportWriterFake;
        $this->app->instance(DailyReportWriter::class, $writer);
        config(['services.anthropic.key' => 'cle', 'services.anthropic.budget_usd' => null]);
        $sejour->observations()->create(['day' => '2026-11-03', 'minute' => 700, 'kind' => 'eats', 'value' => 'tout', 'by_name' => 'Julie Muller']);

        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport'), [], $this->enTetes($this->jeton))->assertOk();

        $this->assertStringNotContainsString('555 66 77', $writer->calls[0]);
        $this->assertStringNotContainsString('0324441122', $writer->calls[0]);
    }

    // ————— F3 : la prise sait de quel flacon entamé elle vient —————

    public function test_annuler_apres_avoir_jete_son_flacon_ne_remplit_pas_le_flacon_ouvert_depuis(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $premiere = $this->prise($sejour, $produit, 840);
        $this->give($sejour, $premiere);
        $this->postJson("/api/v1/stocks/produits/{$produit->id}/entame/jeter", ['motif' => 'casse'], $this->enTetes($this->jeton))->assertOk();
        $this->travelTo(now()->addMinutes(10));
        $seconde = $this->prise($sejour, $produit, 900);
        $this->give($sejour, $seconde);
        $this->assertSame([1, 18.0], $this->stock($produit));

        $stock = $this->cancel($sejour, $premiere);

        $this->assertTrue($stock['lost']);
        $this->assertSame([1, 18.0], $this->stock($produit));
        $this->assertStringContainsString('non remise en stock', MouvementStock::withoutGlobalScopes()->latest('id')->first()->libelle);
    }

    public function test_un_flacon_entame_n_est_jamais_compte_ferme(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise);

        $this->cancel($sejour, $prise);

        // Le flacon a été ouvert : plein ou non, il reste entamé
        // Sixième vérification (G1) : ouvert et revidé par la seule prise annulée, il
        // redevient fermé ; un flacon entamé par d'autres prises ne l'est jamais
        $this->assertSame([3, 0.0], $this->stock($produit));
    }

    // ————— Faibles —————

    public function test_annuler_apres_un_changement_d_unite_ne_melange_pas(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise);
        $produit->forceFill(['dose_unit' => 'cp'])->save();

        $stock = $this->cancel($sejour, $prise);

        $this->assertSame([true, 'unit'], [$stock['lost'], $stock['reason']]);
        $this->assertSame([2, 18.0], $this->stock($produit));
    }

    public function test_une_annulation_ne_depasse_pas_la_contenance(): void
    {
        $produit = $this->produit(fermes: 3, entame: 19);
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->give($sejour, $prise);
        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/entame", ['opened_rest' => 19.5, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertOk();

        $stock = $this->cancel($sejour, $prise);

        // Huitième vérification : compté depuis la prise, rien n'y revient, la dose est notée perdue
        $this->assertSame([3, 19.5], $this->stock($produit));
        $this->assertEquals(2, $stock['surplus']);
        $this->assertStringContainsString('compté entre-temps', MouvementStock::withoutGlobalScopes()->latest('id')->first()->libelle);
    }

    public function test_donne_en_avance_apres_la_sortie_est_une_note_tardive_non_facturee(): void
    {
        $sejour = $this->sejour();
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'times' => [390], 'starts_at' => $sejour->admis_le]);
        $prise = SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-04', 'prevu_a' => 390, 'acte' => 'Maropitant']);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 05:50', 'Europe/Zurich'));
        $sejour->forceFill(['sorti_le' => CarbonImmutable::parse('2026-11-04 05:00', 'Europe/Zurich')->utc()])->save();

        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), ['time' => 320], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), ['time' => 290], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('unbilled', true)->assertJsonPath('unbilled_reason', 'discharged');
        $this->assertNotNull($prise->refresh()->late_noted_at);
    }

    public function test_le_motif_d_une_prise_non_facturee_distingue_la_sortie_au_meme_moment(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $avantSortie = Sejour::withoutGlobalScopes()->find($sejour->id);
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        app(ContexteClinique::class)->definir($this->clinique);

        $this->assertSame('raced', app(CareGiving::class)->give($avantSortie, $prise->id, [], null)['unbilled_reason']);
    }

    public function test_arreter_ne_supprime_jamais_une_prise_donnee_entre_la_lecture_et_l_effacement(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, 900);
        // Sonde : la prise est donnée par un autre poste juste après que l'arrêt a lu les prises
        $isDone = false;
        DB::listen(function ($query) use ($prise, &$isDone): void {
            if (! $isDone && str_contains($query->sql, 'from "sejour_soins"') && str_contains($query->sql, '"treatment_id" = ?')) {
                $isDone = true;
                DB::table('sejour_soins')->where('id', $prise->id)->update(['fait' => true, 'fait_le' => now()]);
            }
        });

        $this->postJson($this->url($sejour, "/traitements/{$prise->treatment_id}/arreter"), [], $this->enTetes($this->jeton))->assertOk();

        $this->assertTrue($isDone);
        $this->assertNotNull(SejourSoin::query()->find($prise->id));
    }

    public function test_compter_le_reste_dans_l_unite_de_l_article_et_declarer_un_reste(): void
    {
        $produit = $this->produit(fermes: 3);
        $url = "/api/v1/stocks/produits/{$produit->id}/entame";

        $this->patchJson($url, ['opened_rest' => 12, 'dose_unit' => 'cp'], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('dose_unit');
        // Pas encore de reste : on en déclare un
        $this->patchJson($url, ['opened_rest' => 12, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertOk();
        $stock = Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
        $this->assertSame([3, 12.0], [$stock->quantite, (float) $stock->opened_rest]);
        $this->assertNotNull($stock->opened_at);
    }
}
