<?php

namespace Tests\Feature\Hospitalisation;

use App\Auth\ContexteClinique;
use App\Enums\Espece;
use App\Enums\MotifAjustementStock;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\MessageClientele;
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
use App\Services\Hospitalisation\CareCancellation;
use App\Services\Hospitalisation\DailyReports;
use App\Services\Hospitalisation\DailyReportWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\DailyReportWriterFake;
use Tests\TestCase;

/**
 * Hospitalisation, troisième vérification (03.11.2026) : donner en avance
 * une prise prévue de la feuille suivante, garder le flacon entamé quand le
 * dernier flacon fermé part, dire à côté du rapport signé tout ce qui a
 * changé après la signature, la fenêtre de 2 heures sur la feuille du jour,
 * un masquage limité aux vrais numéros de téléphone, la contenance figée à
 * l'annulation, l'annulation vérifiée sous verrou. Il est 14:05 le mardi
 * 3 novembre 2026 à la clinique.
 */
class RoundThirdCheckTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

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
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Rachel Solal']);
        $this->proprietaire = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'user_id' => User::factory()->create()->id]);
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

    private function produit(int $fermes = 3, float $entame = 0): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Cerenia 10 mg/ml · 20 ml', 'categorie' => 'med',
            'unite' => 'flacon', 'prix' => 60, 'est_stupefiant' => false, 'dose_unit' => 'ml', 'content_per_unit' => 20,
        ]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $fermes, 'opened_rest' => $entame]));

        return $produit;
    }

    private function stock(Produit $produit): array
    {
        $stock = Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();

        return [$stock->quantite, (float) $stock->opened_rest];
    }

    private function traitement(Sejour $sejour, ?Produit $produit = null, array $heures = [600]): StayTreatment
    {
        return StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'dose' => '1 mg/kg IV', 'dose_amount' => $produit ? 2 : null, 'product_id' => $produit?->id, 'times' => $heures, 'starts_at' => $sejour->admis_le]);
    }

    private function rapportSigne(Sejour $sejour, string $jour = '2026-11-03'): void
    {
        StaySheet::create(['sejour_id' => $sejour->id, 'day' => $jour, 'opened_at' => now(), 'report_status' => 'signed', 'report_text' => 'Rapport signé.', 'report_signed_at' => now()->subMinutes(30), 'report_signed_by_name' => 'Dr Anna Meyer']);
    }

    private function ajouts(Sejour $sejour, string $jour = '2026-11-03'): array
    {
        return array_column($this->getJson($this->url($sejour, "/feuilles/{$jour}"), $this->enTetes($this->jeton))->assertOk()->json('data.report.late_additions'), 'label');
    }

    // ————— D1 : une prise prévue de la feuille suivante se donne en avance —————

    public function test_a_05_50_la_prise_prevue_de_06_30_se_donne_en_la_creant(): void
    {
        $sejour = $this->sejour();
        $traitement = $this->traitement($sejour, heures: [390]);

        $this->travelTo(CarbonImmutable::parse('2026-11-04 04:00', 'Europe/Zurich'));
        $url = $this->url($sejour, "/traitements/{$traitement->id}/prises");
        $this->postJson($url, ['day' => '2026-11-04', 'minute' => 390], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('soin');

        $this->travelTo(CarbonImmutable::parse('2026-11-04 05:50', 'Europe/Zurich'));
        // Elle n'est encore que prévue : pas en base
        $this->assertSame(0, SejourSoin::query()->whereDate('jour', '2026-11-04')->where('prevu_a', 390)->count());
        $this->postJson($url, ['day' => '2026-11-04', 'minute' => 390], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.fait', true);

        $prise = SejourSoin::query()->whereDate('jour', '2026-11-04')->where('prevu_a', 390)->sole();
        $this->assertSame('2026-11-04 05:50', $prise->fait_le->setTimezone('Europe/Zurich')->format('Y-m-d H:i'));
        // Une heure qui n'est pas au traitement, ou une prise déjà donnée : refusées
        $this->postJson($url, ['day' => '2026-11-04', 'minute' => 400], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('minute');
        $this->postJson($url, ['day' => '2026-11-04', 'minute' => 390], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('soin');
    }

    // ————— D2 : le flacon entamé n'est pas effacé avec le dernier flacon fermé —————

    public function test_la_casse_du_dernier_flacon_ferme_garde_le_flacon_entame(): void
    {
        $produit = $this->produit(fermes: 1, entame: 5);
        app(ContexteClinique::class)->definir($this->clinique);

        app(GestionnaireStock::class)->appliquer($produit, -1, 'Casse', motif: MotifAjustementStock::Casse);

        $this->assertSame([0, 5.0], $this->stock($produit));
    }

    public function test_le_flacon_entame_se_jette_avec_un_motif_et_une_trace_meme_sans_flacon_ferme(): void
    {
        $produit = $this->produit(fermes: 0, entame: 5);
        $url = "/api/v1/stocks/produits/{$produit->id}/entame/jeter";

        $this->postJson($url, ['motif' => 'peremp'], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame([0, 0.0], $this->stock($produit));
        $mouvement = MouvementStock::withoutGlobalScopes()->sole();
        $this->assertSame([0, -5.0, 'peremp'], [$mouvement->quantite, (float) $mouvement->dose, $mouvement->motif->value]);
        $this->postJson($url, ['motif' => 'peremp'], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('produit');
    }

    public function test_rupture_seulement_quand_flacons_fermes_et_reste_entame_ne_suffisent_pas(): void
    {
        $produit = $this->produit(fermes: 0, entame: 5);
        app(ContexteClinique::class)->definir($this->clinique);

        $sortie = app(GestionnaireStock::class)->takeDose($produit, 2, 'Soin');

        $this->assertFalse($sortie['rupture']);
        $this->assertSame([0, 3.0], $this->stock($produit));
    }

    // ————— D3 : ce qui change après la signature apparaît à côté du rapport —————

    public function test_une_prise_annulee_apres_la_signature_s_ajoute_a_cote_du_rapport(): void
    {
        $sejour = $this->sejour();
        $traitement = $this->traitement($sejour);
        $prise = SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-03', 'prevu_a' => 600, 'acte' => 'Maropitant', 'fait' => true, 'fait_le' => now()->subHours(4), 'done_by_name' => 'Julie Muller']);
        $this->rapportSigne($sejour);

        $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->url($sejour, '/observations'), ['kind' => 'eats', 'value' => 'tout', 'sheet_day' => '2026-11-03', 'time' => 720], $this->enTetes($this->jeton))->assertCreated();

        $this->assertSame([
            'Maropitant de 10:00 annulé le 3.11 à 14:05 par Dr Rachel Solal',
            'Mange : tout à 12:00, noté par Dr Rachel Solal',
        ], $this->ajouts($sejour));
    }

    public function test_une_prise_notee_apres_coup_le_dit_et_l_envoi_au_proprietaire_contient_les_ajouts(): void
    {
        Mail::fake();
        $sejour = $this->sejour();
        $traitement = $this->traitement($sejour);
        $prise = SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-02', 'prevu_a' => 600, 'acte' => 'Maropitant']);
        $this->rapportSigne($sejour, '2026-11-02');
        ['jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Muller']);

        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), ['time' => 600], $this->enTetes($jetonAsv))->assertOk();
        // Quatrième vérification : « après coup » une seule fois, avec le jour et l'heure de la note
        $this->assertSame(['Maropitant donné à 10:00, noté après coup le 3.11 à 14:05 par Julie Muller'], $this->ajouts($sejour, '2026-11-02'));

        $this->postJson($this->url($sejour, '/feuilles/2026-11-02/rapport/envoyer'), [], $this->enTetes($this->jeton))->assertOk();
        $texte = MessageClientele::query()->sole()->texte;
        $this->assertStringContainsString('Rapport signé.', $texte);
        $this->assertStringContainsString('Ajouté après la signature', $texte);
        $this->assertStringContainsString('Maropitant donné à 10:00, noté après coup le 3.11 à 14:05 par Julie Muller', $texte);
    }

    // ————— D4 : sur la feuille du jour aussi, au plus 2 heures en avance —————

    public function test_sur_la_feuille_du_jour_une_prise_ne_se_donne_pas_plus_de_2_heures_en_avance(): void
    {
        $sejour = $this->sejour();
        $soir = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 1320, 'acte' => 'Maropitant']);
        $bientot = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-03', 'prevu_a' => 960, 'acte' => 'Maropitant']);

        $this->postJson($this->url($sejour, "/soins/{$soir->id}/faire"), [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('soin');
        $this->postJson($this->url($sejour, "/soins/{$bientot->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();
    }

    // ————— D5 : seuls les vrais numéros de téléphone sont masqués —————

    public function test_le_masquage_ne_touche_ni_les_doses_ni_les_heures_ni_les_dates(): void
    {
        $intact = '10 000 000 UI ; 0.5 - 1 - 1.5 ml ; 8 12 16 20 24 h ; 3.11.26 - 5.11.26 ; 120 mg à 08:00, T° 38,5';
        $this->assertSame($intact, DailyReports::withoutContacts($intact));

        $this->assertSame(
            '[téléphone], [téléphone], [téléphone], [téléphone], [téléphone], [téléphone], [téléphone]',
            DailyReports::withoutContacts('+41 79 123 45 67, 0041 21 555 66 77, +33 6 12 34 56 78, 0033 1 23 45 67 89, 079 123 45 67, 06 12 34 56 78, 0612345678'),
        );
    }

    // ————— D6 : l'annulation rend la dose avec la contenance figée sur la prise —————

    public function test_annuler_rend_la_dose_avec_la_contenance_du_jour_de_la_prise(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $traitement = $this->traitement($sejour, $produit);
        $prise = SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-03', 'prevu_a' => 840, 'acte' => 'Maropitant', 'dose_amount' => 2, 'produit_id' => $produit->id]);
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame([2, 18.0], $this->stock($produit));

        $produit->forceFill(['content_per_unit' => 10])->save();
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/annuler"), [], $this->enTetes($this->jeton))->assertOk();

        // 18 + 2 = 20 ml : le flacon de 20 ml du jour de la prise, ouvert par elle
        // seule, redevient fermé (sixième vérification, G1), pas deux de 10
        $this->assertSame([3, 0.0], $this->stock($produit));
    }

    // ————— D8 : l'annulation vérifie la sortie sous verrou —————

    public function test_une_annulation_qui_a_lu_le_sejour_avant_la_sortie_est_refusee(): void
    {
        $produit = $this->produit(fermes: 3);
        $sejour = $this->sejour();
        $traitement = $this->traitement($sejour, $produit);
        $prise = SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-03', 'prevu_a' => 840, 'acte' => 'Maropitant', 'dose_amount' => 2, 'produit_id' => $produit->id]);
        $this->postJson($this->url($sejour, "/soins/{$prise->id}/faire"), [], $this->enTetes($this->jeton))->assertOk();
        // Sonde : l'annulation a lu le séjour avant que la sortie passe
        $avantSortie = Sejour::withoutGlobalScopes()->find($sejour->id);
        $this->postJson($this->url($sejour, '/sortie'), [], $this->enTetes($this->jeton))->assertOk();

        app(ContexteClinique::class)->definir($this->clinique);
        $this->expectException(ValidationException::class);
        try {
            app(CareCancellation::class)->cancel($avantSortie, $prise->id, null);
        } finally {
            $this->assertNull(SejourSoin::query()->find($prise->id)->cancelled_at);
            $this->assertSame([2, 18.0], $this->stock($produit));
        }
    }
}
