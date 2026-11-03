<?php

namespace Tests\Feature\Hospitalisation;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\Espece;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\MouvementStock;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StaySheet;
use App\Models\StayTreatment;
use App\Models\Stock;
use App\Models\User;
use App\Services\GestionnaireStock;
use App\Services\Hospitalisation\DailyReports;
use App\Services\Hospitalisation\DailyReportWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\DailyReportWriterFake;
use Tests\TestCase;

/**
 * Hospitalisation, deuxième vérification (03.11.2026) : correction et
 * signature sans course, la frontière de 06:00, la facture de sortie au
 * propriétaire de l'admission, l'annulation d'une prise, la note tardive
 * après la sortie, le prix et la contenance figés sur la prise, le flacon
 * entamé dans le stock, la contenance réservée au droit Stocks, le
 * nettoyage des textes envoyés à l'IA. Il est 14:05 le mardi
 * 3 novembre 2026 à la clinique.
 */
class RoundSecondCheckTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $vet;

    private string $jeton;

    private Patient $chien;

    private Client $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:05', 'Europe/Zurich'));
        config(['services.anthropic.key' => 'cle-de-test', 'services.anthropic.budget_usd' => null]);
        $this->app->instance(DailyReportWriter::class, new DailyReportWriterFake);

        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        ['employe' => $this->vet, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Rachel Solal']);
        $this->proprietaire = Client::factory()->create(['organisation_id' => $this->organisationId(), 'user_id' => User::factory()->create()->id]);
        $this->chien = Patient::factory()->for($this->proprietaire)->create(['espece' => Espece::Chien, 'nom' => 'Tao']);
    }

    private function organisationId(): int
    {
        return $this->clinique->societe->organisation_id;
    }

    private function sejour(string $admis = '2026-11-01 09:15', array $plus = []): Sejour
    {
        return Sejour::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->chien->id,
            'client_id' => $this->proprietaire->id,
            'box' => 2,
            'statut' => 'surv',
            'perfusion' => null,
            'admis_le' => CarbonImmutable::parse($admis, 'Europe/Zurich')->utc(),
            ...$plus,
        ]);
    }

    private function url(Sejour $sejour, string $suite = ''): string
    {
        return "/api/v1/hospitalisation/sejours/{$sejour->id}{$suite}";
    }

    private function produit(array $plus = [], int $quantite = 3): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->organisationId(), 'nom' => 'Cerenia 10 mg/ml · 20 ml', 'categorie' => 'med',
            'unite' => 'flacon', 'prix' => 60, 'est_stupefiant' => false, 'dose_unit' => 'ml', 'content_per_unit' => 20, ...$plus,
        ]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $quantite]));

        return $produit;
    }

    /** Une prise de 2 ml d'un traitement lié au produit */
    private function prise(Sejour $sejour, Produit $produit, string $jour = '2026-11-03', int $minute = 600): SejourSoin
    {
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'dose' => '1 mg/kg IV', 'dose_amount' => 2, 'product_id' => $produit->id, 'times' => [$minute], 'starts_at' => $sejour->admis_le]);

        return SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => $jour, 'prevu_a' => $minute, 'acte' => 'Maropitant', 'dose' => '1 mg/kg IV', 'dose_amount' => 2, 'produit_id' => $produit->id]);
    }

    private function stock(Produit $produit): Stock
    {
        return Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
    }

    private function care(Sejour $sejour, int $id, string $jour = '2026-11-03'): array
    {
        $ronde = $this->getJson("/api/v1/hospitalisation/ronde?from={$jour}&to={$jour}&sejour_id={$sejour->id}", $this->enTetes($this->jeton))->assertOk()->json('data');

        return collect($ronde['stays'][0]['cares'])->firstWhere('id', $id);
    }

    // ————— N1 : correction et signature, sans course —————

    public function test_une_correction_arrivee_apres_la_signature_n_est_pas_enregistree(): void
    {
        $sejour = $this->sejour();
        StaySheet::create(['sejour_id' => $sejour->id, 'day' => '2026-11-03', 'opened_at' => now(), 'report_status' => 'to_review', 'report_text' => 'Version relue.', 'report_source' => 'ai']);
        $reports = app(DailyReports::class);
        // Sonde : la correction a lu la feuille avant que la signature passe
        $avantSignature = StaySheet::query()->whereDate('day', '2026-11-03')->sole();

        $this->postJson($this->url($sejour, '/feuilles/2026-11-03/rapport/signer'), ['text' => 'Version relue.'], $this->enTetes($this->jeton))->assertOk();

        try {
            $reports->edit($sejour, $avantSignature, 'Correction tardive.');
            $this->fail('La correction aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertSame('Ce rapport vient d’être signé : la correction n’est pas enregistrée. Notez-la après coup sur la feuille.', $e->errors()['text'][0]);
        }
        $this->assertSame('Version relue.', StaySheet::query()->whereDate('day', '2026-11-03')->sole()->report_text);
    }

    public function test_la_signature_verifie_le_texte_sous_verrou(): void
    {
        $sejour = $this->sejour();
        StaySheet::create(['sejour_id' => $sejour->id, 'day' => '2026-11-03', 'opened_at' => now(), 'report_status' => 'to_review', 'report_text' => 'Version 1.', 'report_source' => 'ai']);
        $reports = app(DailyReports::class);
        // Sonde : la signature a lu la version 1, une correction passe entre-temps
        $avantCorrection = StaySheet::query()->whereDate('day', '2026-11-03')->sole();
        $this->patchJson($this->url($sejour, '/feuilles/2026-11-03/rapport'), ['text' => 'Version 2.'], $this->enTetes($this->jeton))->assertOk();

        $this->expectException(ValidationException::class);
        try {
            $reports->sign($sejour, $avantCorrection, null, 'Version 1.');
        } finally {
            $this->assertSame('to_review', StaySheet::query()->whereDate('day', '2026-11-03')->sole()->report_status);
        }
    }

    // ————— N2 : la frontière de 06:00 —————

    public function test_une_prise_de_06_30_se_donne_en_avance_a_05_50(): void
    {
        $sejour = $this->sejour();
        $soin = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-04', 'prevu_a' => 390, 'acte' => 'Maropitant']);

        $this->travelTo(CarbonImmutable::parse('2026-11-04 04:20', 'Europe/Zurich'));
        $this->postJson($this->url($sejour, "/soins/{$soin->id}/faire"), [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('soin');

        $this->travelTo(CarbonImmutable::parse('2026-11-04 05:50', 'Europe/Zurich'));
        $this->postJson($this->url($sejour, "/soins/{$soin->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();

        $care = $this->care($sejour, $soin->id, '2026-11-04');
        $this->assertSame('2026-11-04', $care['sheet_day']);
        $this->assertSame('05:50', $care['done_time']);
        $this->assertFalse($care['is_late_note']);
    }

    public function test_la_nuit_un_soin_planifie_sans_jour_va_sur_la_feuille_en_cours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 00:30', 'Europe/Zurich'));
        $sejour = $this->sejour();

        $id = $this->postJson($this->url($sejour, '/soins'), ['prevu_a' => 630, 'acte' => 'Méloxicam'], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->assertSame('2026-11-03', SejourSoin::query()->find($id)->jour->toDateString());
        $this->postJson($this->url($sejour, "/soins/{$id}/faire"), [], $this->enTetes($this->jeton))->assertOk();
    }

    // ————— N3 : la facture de sortie au propriétaire de l'admission —————

    public function test_la_facture_de_sortie_va_au_proprietaire_de_l_admission_et_sa_dette_ne_suit_pas_l_animal(): void
    {
        $sejour = $this->sejour('2026-11-02 09:00');
        $nouveau = Client::factory()->create(['organisation_id' => $this->organisationId(), 'nom' => 'Nouveau']);
        $this->chien->forceFill(['client_id' => $nouveau->id])->save();

        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();

        $facture = Facture::withoutGlobalScopes()->sole();
        $this->assertSame($this->proprietaire->id, $facture->client_id);
        $this->assertSame($this->proprietaire->nom_affiche, $facture->owner_name);
        // La dette suit la facture : rien chez le nouveau propriétaire
        $this->assertEquals(0, (float) $this->chien->refresh()->dette);
    }

    // ————— N4 : annuler une prise donnée par erreur —————

    public function test_une_prise_annulee_rend_sa_dose_au_stock_et_ne_se_facture_plus(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour('2026-11-02 09:00');
        $prise = $this->prise($sejour, $produit);
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame([2, 18.0], [$this->stock($produit)->quantite, (float) $this->stock($produit)->opened_rest]);

        $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))->assertOk();

        // La prise rend à l'identique ce qu'elle a sorti : le flacon qu'elle seule a
        // ouvert redevient fermé (sixième vérification, G1)
        $this->assertSame([3, 0.0], [$this->stock($produit)->quantite, (float) $this->stock($produit)->opened_rest]);
        $retour = MouvementStock::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(1, $retour->quantite);
        $this->assertEquals(2.0, (float) $retour->dose);

        $care = $this->care($sejour, $prise->id);
        $this->assertSame('cancelled', $care['status']);
        $this->assertSame('Annulée par Dr Rachel Solal à 14:05', $care['cancelled_label']);
        $lignes = collect($this->getJson($this->url($sejour, '/facture'), $this->enTetes($this->jeton))->json('data.lignes'));
        $this->assertNull($lignes->first(fn (array $l) => str_starts_with($l['libelle'], 'Maropitant')));
    }

    public function test_sur_une_feuille_close_l_annulation_est_marquee_apres_coup_et_demande_le_droit(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, '2026-11-02');
        $prise->forceFill(['fait' => true, 'fait_le' => now()->subDay(), 'done_by_name' => 'Julie Muller'])->save();

        ['employe' => $sansRole, 'jeton' => $jetonSansRole] = $this->employeAvecJeton($this->clinique);
        $sansRole->update(['role_id' => null]);
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($jetonSansRole))->assertForbidden();

        $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame('Annulée par Dr Rachel Solal à 14:05, après coup', $this->care($sejour, $prise->id, '2026-11-02')['cancelled_label']);
    }

    // ————— Note tardive après la sortie —————

    public function test_apres_la_sortie_une_note_sans_effet_sur_l_argent_reste_possible(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit, '2026-11-03', 600);
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();
        $avant = Facture::withoutGlobalScopes()->sole()->totalTtc();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson($this->url($sejour, '/observations'), ['kind' => 'eats', 'value' => 'tout', 'sheet_day' => '2026-11-03', 'time' => 720], $entetes)->assertCreated();
        $this->postJson($this->url($sejour, '/douleurs'), ['score' => 2, 'sheet_day' => '2026-11-03', 'time' => 720], $entetes)->assertCreated();
        $this->postJson($this->url($sejour, '/constantes'), ['sheet_day' => '2026-11-03', 'mesure_a' => 720, 'temperature' => 38.4, 'fc' => 90, 'fr' => 20], $entetes)->assertCreated();
        $this->postJson($this->url($sejour, '/soins'), ['sheet_day' => '2026-11-03', 'prevu_a' => 700, 'acte' => 'Pansement'], $entetes)->assertCreated();
        $this->postJson($this->url($sejour, '/soins'), ['sheet_day' => '2026-11-03', 'prevu_a' => 700, 'acte' => 'Pansement', 'produit_id' => $produit->id], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('produit_id');
        // Sans heure, la note ne se rattache à rien
        $this->postJson($this->url($sejour, '/observations'), ['kind' => 'eats', 'value' => 'tout'], $entetes)->assertUnprocessable()->assertJsonValidationErrors('time');

        // Une prise notée après la sortie sort le stock, mais ne se facture pas
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), ['time' => 610], $entetes)->assertOk();
        $this->assertSame([2, 18.0], [$this->stock($produit)->quantite, (float) $this->stock($produit)->opened_rest]);
        $this->assertSame('Facture déjà émise : cette prise n’est pas facturée', $this->care($sejour, $prise->id)['unbilled_label']);
        $this->assertSame($avant, Facture::withoutGlobalScopes()->sole()->refresh()->totalTtc());
    }

    // ————— N5 : prix et contenance figés sur la prise —————

    public function test_le_prix_et_la_contenance_sont_ceux_du_jour_de_la_prise(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour('2026-11-02 09:00');
        $prise = $this->prise($sejour, $produit);
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();

        $produit->forceFill(['prix' => 100, 'content_per_unit' => 10])->save();

        $med = collect($this->getJson($this->url($sejour, '/facture'), $this->enTetes($this->jeton))->json('data.lignes'))
            ->first(fn (array $l) => str_starts_with($l['libelle'], 'Maropitant'));
        // 2 ml × 60 / 20, le prix et la contenance du jour de la prise
        $this->assertEquals(6.0, $med['total_chf']);
    }

    // ————— N6 : le flacon entamé dans le stock —————

    // N6 révisée par la troisième vérification (D2) : le flacon entamé reste
    // quand le dernier flacon fermé part (RoundThirdCheckTest)

    public function test_aucune_ligne_vide_au_journal_quand_rien_ne_sort(): void
    {
        $produit = $this->produit(quantite: 0);
        app(ContexteClinique::class)->definir($this->clinique);

        $sortie = app(GestionnaireStock::class)->takeDose($produit, 2, 'Soin');

        $this->assertTrue($sortie['rupture']);
        $this->assertSame(0, MouvementStock::withoutGlobalScopes()->count());
    }

    public function test_l_ecran_stocks_voit_la_dose_le_flacon_entame_et_modifie_la_contenance(): void
    {
        $produit = $this->produit();
        $sejour = $this->sejour();
        $prise = $this->prise($sejour, $produit);
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();
        $entetes = $this->enTetes($this->jeton);

        $this->getJson("/api/v1/stocks/produits/{$produit->id}/mouvements", $entetes)->assertOk()
            ->assertJsonPath('data.0.dose', -2)->assertJsonPath('data.0.dose_unit', 'ml');
        $this->getJson("/api/v1/stocks/produits/{$produit->id}", $entetes)->assertOk()
            ->assertJsonPath('data.content_per_unit', 20)
            ->assertJsonPath('data.dose_unit', 'ml')
            ->assertJsonPath('data.stocks.0.opened_rest', 18);

        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/contenance", ['content_per_unit' => 25, 'dose_unit' => 'ml'], $entetes)
            ->assertOk()->assertJsonPath('data.content_per_unit', 25);
        DerogationPermission::create(['employe_id' => $this->vet->id, 'domaine' => DomainePermission::Stocks, 'est_accordee' => false]);
        $this->patchJson("/api/v1/stocks/produits/{$produit->id}/contenance", ['content_per_unit' => 30, 'dose_unit' => 'ml'], $this->enTetes($this->jeton))->assertForbidden();
    }

    // ————— N7 : la contenance au catalogue demande le droit Stocks —————

    public function test_sans_le_droit_stocks_la_prescription_garde_la_dose_sans_ecrire_au_catalogue(): void
    {
        $produit = $this->produit(['dose_unit' => null, 'content_per_unit' => null]);
        $sejour = $this->sejour();
        DerogationPermission::create(['employe_id' => $this->vet->id, 'domaine' => DomainePermission::Stocks, 'est_accordee' => false]);

        $this->postJson($this->url($sejour, '/traitements'), ['label' => 'Maropitant', 'product_id' => $produit->id, 'dose_amount' => 2, 'times' => [1380], 'product_content' => 20, 'product_dose_unit' => 'ml'], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.dose_amount', 2)
            ->assertJsonPath('warning', 'Sans le droit Stocks, la contenance n’est pas enregistrée au catalogue : les prises ne sortiront pas du stock.');
        $this->assertNull($produit->refresh()->content_per_unit);
    }

    // ————— N9 : numéros français et internationaux ; constantes à venir —————

    public function test_les_numeros_francais_et_internationaux_sont_masques(): void
    {
        $texte = DailyReports::withoutContacts('Appeler le 06 12 34 56 78, le +33 6 12 34 56 78, le 01.23.45.67.89 ou le +44 20 7946 0958 ; Méloxicam 0,1 mg/kg à 08:00, T° 38,5.');

        // Quatrième vérification (N2) : les numéros internationaux en « + » de 9 à 15 chiffres aussi
        $this->assertSame('Appeler le [téléphone], le [téléphone], le [téléphone] ou le [téléphone] ; Méloxicam 0,1 mg/kg à 08:00, T° 38,5.', $texte);
    }

    public function test_une_constante_sans_jour_refuse_une_heure_a_venir(): void
    {
        $sejour = $this->sejour();

        $this->postJson($this->url($sejour, '/constantes'), ['mesure_a' => 1000, 'temperature' => 38.4, 'fc' => 90, 'fr' => 20], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('mesure_a');
    }
}
