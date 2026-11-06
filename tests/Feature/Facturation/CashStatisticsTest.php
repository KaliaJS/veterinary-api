<?php

namespace Tests\Feature\Facturation;

use App\Auth\ContexteClinique;
use App\Enums\MoyenPaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutRendezVous;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeRendezVous;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Statistiques de la Caisse sur une période choisie (demande de Jim,
 * 06.11.2026 : l'établi B avec les courbes de A, la période à la poignée) :
 * chiffres, séries, toute l'histoire et ventilations servis par l'API,
 * comptés au jour de la clinique, dans l'organisation du jeton seulement.
 */
class CashStatisticsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        // Mercredi 4 novembre 2026, 16 h à Zurich
        $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00:00', 'UTC'));

        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'fuseau_horaire' => 'Europe/Zurich']);
        $this->cliniqueSoeur = Clinique::factory()->create(['nom' => 'Le Lac', 'societe_id' => $this->clinique->societe_id, 'fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    /** @param  array<string, mixed>  $attributs */
    private function payee(string $payeeLeUtc, float $montant, MoyenPaiement $moyen = MoyenPaiement::Tpe, array $attributs = []): Facture
    {
        $facture = Facture::factory()->payee($moyen)->create([
            'clinique_id' => $this->clinique->id,
            'emise_le' => CarbonImmutable::parse($payeeLeUtc, 'UTC'),
            'payee_le' => CarbonImmutable::parse($payeeLeUtc, 'UTC'),
            'species' => 'CH',
            ...$attributs,
        ]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => $montant, 'type' => TypeLigneFacture::Acte, 'libelle' => 'Consultation']);

        return $facture;
    }

    /** @param  array<string, mixed>  $parametres */
    private function stats(array $parametres): TestResponse
    {
        return $this->getJson('/api/v1/facturation/statistics?'.http_build_query($parametres), $this->enTetes($this->jeton));
    }

    public function test_les_indicateurs_de_la_periode_sont_servis_au_jour_de_la_clinique(): void
    {
        // 00:30 le 2 novembre à Zurich (encore le 1er en UTC) : dans la période
        $this->payee('2026-11-01 23:30:00', 100.00, MoyenPaiement::Tpe);
        // 23:30 le 1er novembre à Zurich : hors de la période
        $this->payee('2026-11-01 22:30:00', 60.00, MoyenPaiement::Especes);
        // Un acompte payé en ligne à la réservation : déduit à la caisse, compté dans l'encaissé
        $this->payee('2026-11-03 10:00:00', 50.00, MoyenPaiement::Twint, ['acompte' => 20, 'species' => 'CT']);
        // Une facture due n'est pas encaissée
        $due = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'emise_le' => CarbonImmutable::parse('2026-11-03 09:00:00', 'UTC')]);
        FactureLigne::factory()->create(['facture_id' => $due->id, 'quantite' => 1, 'prix_chf' => 500]);

        $reponse = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04'])->assertOk();

        $reponse->assertJsonPath('data.period.from', '2026-11-02')
            ->assertJsonPath('data.period.to', '2026-11-04')
            ->assertJsonPath('data.period.timezone', 'Europe/Zurich')
            ->assertJsonPath('data.comparison', null)
            ->assertJsonPath('data.indicators.revenue.value', 150)
            ->assertJsonPath('data.indicators.paid_count.value', 2)
            ->assertJsonPath('data.indicators.average_basket.value', 75)
            ->assertJsonPath('data.indicators.revenue.previous', null)
            ->assertJsonPath('data.indicators.revenue.change_pct', null);

        $paiements = collect($reponse->json('data.payment_methods'))->keyBy('key');
        $this->assertSame(100, (int) $paiements['tpe']['total']);
        $this->assertSame(30, (int) $paiements['twint']['total']);
        $this->assertSame(20, (int) $paiements['deposit']['total']);
        $this->assertSame(67, $paiements['tpe']['share_pct']);

        $especes = collect($reponse->json('data.species'))->keyBy('key');
        $this->assertSame(1, $especes['CH']['count']);
        $this->assertSame('Chat', $especes['CT']['label']);
        $this->assertSame(50, $especes['CH']['share_pct']);
    }

    public function test_la_comparaison_avec_la_periode_precedente_et_l_an_dernier_est_servie(): void
    {
        $this->payee('2026-11-02 10:00:00', 150.00);
        // Période précédente de trois jours : du 30 octobre au 1er novembre
        $this->payee('2026-11-01 10:00:00', 60.00);
        // La même période l'an dernier
        $this->payee('2025-11-03 10:00:00', 300.00);

        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04', 'compare' => 'previous'])->assertOk()
            ->assertJsonPath('data.comparison.kind', 'previous')
            ->assertJsonPath('data.comparison.from', '2026-10-30')
            ->assertJsonPath('data.comparison.to', '2026-11-01')
            ->assertJsonPath('data.indicators.revenue.previous', 60)
            ->assertJsonPath('data.indicators.revenue.change_pct', 150)
            ->assertJsonPath('data.series.comparison.2.revenue', 60);

        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04', 'compare' => 'last_year'])->assertOk()
            ->assertJsonPath('data.comparison.from', '2025-11-02')
            ->assertJsonPath('data.comparison.to', '2025-11-04')
            ->assertJsonPath('data.indicators.revenue.previous', 300)
            ->assertJsonPath('data.indicators.revenue.change_pct', -50)
            ->assertJsonPath('data.indicators.paid_count.change_pct', 0);
    }

    public function test_un_mois_entier_se_compare_au_mois_d_avant(): void
    {
        $this->stats(['from' => '2026-10-01', 'to' => '2026-10-31', 'compare' => 'previous'])->assertOk()
            ->assertJsonPath('data.comparison.from', '2026-09-01')
            ->assertJsonPath('data.comparison.to', '2026-09-30');
    }

    /** Vérification du 06.11.2026 (M2) : un jour seul se compare à la veille, même un 1er du mois. */
    public function test_aujourd_hui_un_premier_se_compare_a_la_veille(): void
    {
        $this->stats(['from' => '2026-11-01', 'to' => '2026-11-01', 'compare' => 'previous'])->assertOk()
            ->assertJsonPath('data.comparison.from', '2026-10-31')
            ->assertJsonPath('data.comparison.to', '2026-10-31');
        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-02', 'compare' => 'previous'])->assertOk()
            ->assertJsonPath('data.comparison.from', '2026-11-01');
    }

    /** Vérification du 06.11.2026 (M2) : « Cette année » se compare aux mêmes jours de l'année d'avant. */
    public function test_cette_annee_se_compare_aux_memes_jours_de_l_an_dernier(): void
    {
        $this->stats(['from' => '2026-01-01', 'to' => '2026-11-04', 'compare' => 'previous'])->assertOk()
            ->assertJsonPath('data.comparison.from', '2025-01-01')
            ->assertJsonPath('data.comparison.to', '2025-11-04');
        // Les mêmes jours du mois d'avant, pour quelques jours d'un même mois
        $this->stats(['from' => '2026-11-01', 'to' => '2026-11-04', 'compare' => 'previous'])->assertOk()
            ->assertJsonPath('data.comparison.from', '2026-10-01')
            ->assertJsonPath('data.comparison.to', '2026-10-04');
    }

    /** Vérification du 06.11.2026 (L1) : par semaine, la comparaison garde la même coupe, créneau par créneau. */
    public function test_les_semaines_de_la_comparaison_sont_alignees(): void
    {
        $reponse = $this->stats(['from' => '2026-09-01', 'to' => '2026-11-04', 'compare' => 'previous'])->assertOk();

        $creneaux = $reponse->json('data.series.slots');
        $comparaison = $reponse->json('data.series.comparison');
        $this->assertSame('week', $reponse->json('data.period.granularity'));
        $this->assertCount(count($creneaux), $comparaison);
        $longueur = fn (array $c): int => (int) CarbonImmutable::parse($c['from'])->diffInDays(CarbonImmutable::parse($c['to'])) + 1;
        $this->assertSame(array_map($longueur, $creneaux), array_map($longueur, $comparaison));
        $this->assertSame('2026-06-28', $comparaison[0]['from']);
    }

    /** Vérification du 06.11.2026 (L3) : un acompte plus grand que la facture ne compte que ce qu'il a payé. */
    public function test_les_moyens_de_paiement_totalisent_l_encaisse(): void
    {
        $this->payee('2026-11-02 10:00:00', 50.00, MoyenPaiement::Tpe, ['acompte' => 80]);
        $this->payee('2026-11-03 10:00:00', 100.00, MoyenPaiement::Twint, ['acompte' => 30]);

        $reponse = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04'])->assertOk();

        $paiements = collect($reponse->json('data.payment_methods'))->keyBy('key');
        $this->assertSame(150, (int) $reponse->json('data.indicators.revenue.value'));
        $this->assertEquals(150, collect($reponse->json('data.payment_methods'))->sum('total'));
        $this->assertEquals(80, $paiements['deposit']['total']);
        $this->assertEquals(0, $paiements['tpe']['total']);
        $this->assertEquals(150, array_sum($reponse->json('data.series.slots.0.payments')) + array_sum($reponse->json('data.series.slots.1.payments')));
    }

    /** Vérification du 06.11.2026 (L6) : « pas après aujourd'hui » se lit au fuseau de la clinique choisie. */
    public function test_aujourd_hui_est_celui_de_la_clinique_choisie(): void
    {
        // 15 h UTC le 4 novembre : déjà le 5 novembre à 5 h à Kiritimati (UTC+14)
        $loin = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'fuseau_horaire' => 'Pacific/Kiritimati']);

        $this->stats(['from' => '2026-11-05', 'to' => '2026-11-05', 'clinic_id' => $loin->id])->assertOk();
        $this->stats(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_les_series_sont_par_jour_par_semaine_ou_par_mois_selon_la_longueur(): void
    {
        $this->payee('2026-11-02 10:00:00', 100.00);
        $this->payee('2026-11-03 10:00:00', 40.00);
        $this->payee('2026-11-03 11:00:00', 10.00);

        $jours = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04'])->assertOk();
        $jours->assertJsonPath('data.period.granularity', 'day')
            ->assertJsonCount(3, 'data.series.slots')
            ->assertJsonPath('data.series.slots.0.from', '2026-11-02')
            ->assertJsonPath('data.series.slots.0.revenue', 100)
            ->assertJsonPath('data.series.slots.1.revenue', 50)
            ->assertJsonPath('data.series.slots.1.paid_count', 2)
            ->assertJsonPath('data.series.slots.1.average_basket', 25)
            ->assertJsonPath('data.series.slots.2.revenue', 0)
            ->assertJsonPath('data.series.slots.2.average_basket', null)
            ->assertJsonPath('data.series.slots.0.payments.tpe', 100);

        // Trois mois : par semaine, du lundi au dimanche, la première commencée au début de la période
        $this->stats(['from' => '2026-08-01', 'to' => '2026-11-04'])->assertOk()
            ->assertJsonPath('data.period.granularity', 'week')
            ->assertJsonPath('data.series.slots.0.from', '2026-08-01')
            ->assertJsonPath('data.series.slots.0.to', '2026-08-02')
            ->assertJsonPath('data.series.slots.1.from', '2026-08-03')
            ->assertJsonPath('data.series.slots.1.to', '2026-08-09');

        // Un an : par mois
        $mois = $this->stats(['from' => '2025-11-05', 'to' => '2026-11-04'])->assertOk();
        $mois->assertJsonPath('data.period.granularity', 'month')
            ->assertJsonPath('data.series.slots.0.from', '2025-11-05')
            ->assertJsonPath('data.series.slots.0.to', '2025-11-30')
            ->assertJsonPath('data.series.slots.1.from', '2025-12-01')
            ->assertJsonPath('data.series.slots.12.to', '2026-11-04');
        $this->assertSame(150, (int) collect($mois->json('data.series.slots'))->sum('revenue'));
    }

    public function test_toute_l_histoire_est_servie_pour_l_apercu(): void
    {
        $this->payee('2024-03-05 10:00:00', 80.00);
        $this->payee('2026-11-03 10:00:00', 20.00);

        $reponse = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04'])->assertOk();

        $reponse->assertJsonPath('data.history.first_day', '2024-03-05')
            ->assertJsonPath('data.history.last_day', '2026-11-04')
            ->assertJsonPath('data.history.granularity', 'week');
        $this->assertSame(100, (int) collect($reponse->json('data.history.points'))->sum('revenue'));
        $this->assertSame('2024-03-05', $reponse->json('data.history.points.0.from'));

        // Sans l'histoire quand l'écran l'a déjà
        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04', 'history' => 0])->assertOk()
            ->assertJsonMissingPath('data.history');
    }

    public function test_les_ventilations_par_clinique_par_praticien_et_par_espece_sont_servies(): void
    {
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'nom' => 'Anna Meyer']);
        $this->payee('2026-11-02 10:00:00', 100.00, MoyenPaiement::Tpe, ['ligne_planning_id' => $ligne->id]);
        $this->payee('2026-11-02 11:00:00', 60.00, MoyenPaiement::Tpe, ['ligne_planning_id' => $ligne->id, 'species' => 'CT']);
        $this->payee('2026-11-03 10:00:00', 40.00, MoyenPaiement::Especes, ['clinique_id' => $this->cliniqueSoeur->id]);

        $reponse = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04'])->assertOk();
        $reponse->assertJsonPath('data.clinic_id', null)
            ->assertJsonPath('data.indicators.revenue.value', 200)
            ->assertJsonCount(2, 'data.clinics');

        $cliniques = collect($reponse->json('data.breakdowns.clinic'))->keyBy('key');
        $this->assertSame(160, (int) $cliniques[(string) $this->clinique->id]['revenue']);
        $this->assertSame('Le Lac', $cliniques[(string) $this->cliniqueSoeur->id]['label']);

        $praticiens = collect($reponse->json('data.breakdowns.practitioner'))->keyBy('key');
        $this->assertSame('Anna Meyer', $praticiens[(string) $ligne->id]['label']);
        $this->assertSame(2, $praticiens[(string) $ligne->id]['paid_count']);
        $this->assertEquals(80, $praticiens[(string) $ligne->id]['average_basket']);
        $this->assertSame(40, (int) $praticiens['none']['revenue']);

        $especes = collect($reponse->json('data.breakdowns.species'))->keyBy('key');
        $this->assertSame(140, (int) $especes['CH']['revenue']);

        $paiements = collect($reponse->json('data.breakdowns.payment'))->keyBy('key');
        $this->assertSame(2, $paiements['tpe']['paid_count']);

        // Une seule clinique : celle de la sœur n'y est plus
        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04', 'clinic_id' => $this->cliniqueSoeur->id])->assertOk()
            ->assertJsonPath('data.clinic_id', $this->cliniqueSoeur->id)
            ->assertJsonPath('data.indicators.revenue.value', 40);
    }

    public function test_les_evolutions_des_ventilations_sont_servies(): void
    {
        $this->payee('2026-11-02 10:00:00', 120.00);
        $this->payee('2026-11-01 10:00:00', 100.00);

        $reponse = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04', 'compare' => 'previous'])->assertOk();

        $cliniques = collect($reponse->json('data.breakdowns.clinic'))->keyBy('key');
        $ici = $cliniques[(string) $this->clinique->id];
        $this->assertSame(100, (int) $ici['previous']['revenue']);
        $this->assertSame(20, $ici['change']['revenue']);
    }

    public function test_les_factures_en_attente_sont_servies_avec_leur_age(): void
    {
        foreach ([['2026-09-01 09:00:00', 300], ['2026-10-01 09:00:00', 200], ['2026-10-20 09:00:00', 100]] as [$emiseLe, $montant]) {
            $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'emise_le' => CarbonImmutable::parse($emiseLe, 'UTC'), 'statut' => StatutFacture::Due]);
            FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => $montant]);
        }

        $reponse = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04'])->assertOk();

        $reponse->assertJsonPath('data.indicators.due.total', 600)
            ->assertJsonPath('data.indicators.due.count', 3)
            ->assertJsonPath('data.due.invoices.0.age_days', 64)
            ->assertJsonPath('data.due.invoices.0.amount', 300);
        $ages = collect($reponse->json('data.due.aging'))->keyBy('key');
        $this->assertSame(100, (int) $ages['under_30']['total']);
        $this->assertSame(1, $ages['from_30_to_59']['count']);
        $this->assertSame(50, $ages['over_60']['share_pct']);
    }

    public function test_les_actes_factures_sont_classes(): void
    {
        $this->payee('2026-11-02 10:00:00', 80.00);
        $facture = $this->payee('2026-11-03 10:00:00', 80.00);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => 64, 'type' => TypeLigneFacture::Acte, 'libelle' => 'Vaccination']);
        // Un médicament n'est pas un acte
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 3, 'prix_chf' => 12, 'type' => TypeLigneFacture::Medicament, 'libelle' => 'Comprimés']);

        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04'])->assertOk()
            ->assertJsonPath('data.indicators.acts.count', 3)
            ->assertJsonPath('data.acts.0.label', 'Consultation')
            ->assertJsonPath('data.acts.0.count', 2)
            ->assertJsonPath('data.acts.0.revenue', 160)
            ->assertJsonPath('data.acts.1.label', 'Vaccination');
    }

    public function test_l_occupation_du_planning_vient_des_horaires_et_des_rendez_vous(): void
    {
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'nom' => 'Kofi Adjei']);
        $rdv = fn (string $debut, string $fin, array $attributs = []) => RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $ligne->id,
            'starts_at' => CarbonImmutable::parse($debut, 'Europe/Zurich')->utc(),
            'ends_at' => CarbonImmutable::parse($fin, 'Europe/Zurich')->utc(),
            ...$attributs,
        ]);
        // Lundi 2 novembre, horaires par défaut : 08:00 à 12:30 et 14:00 à 19:30, 600 minutes ouvertes
        $rdv('2026-11-02 09:00', '2026-11-02 10:00');
        // Pendant la coupure de midi, seules les minutes ouvertes comptent : 30 + 30
        $rdv('2026-11-02 12:00', '2026-11-02 14:30');
        // Un annulé et un tampon n'occupent pas
        $rdv('2026-11-02 16:00', '2026-11-02 17:00', ['status' => StatutRendezVous::Annule]);
        $rdv('2026-11-02 17:00', '2026-11-02 18:00', ['type' => TypeRendezVous::Tampon]);

        $reponse = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-02'])->assertOk();

        $reponse->assertJsonPath('data.indicators.occupancy.percent', 20)
            ->assertJsonPath('data.indicators.occupancy.booked_minutes', 120)
            ->assertJsonPath('data.indicators.occupancy.open_minutes', 600)
            ->assertJsonPath('data.occupancy.practitioners.0.name', 'Kofi Adjei')
            ->assertJsonPath('data.occupancy.practitioners.0.percent', 20);
        $cases = collect($reponse->json('data.occupancy.heatmap'))->keyBy(fn (array $c): string => $c['weekday'].'-'.$c['hour']);
        $this->assertSame(100, $cases['1-9']['percent']);
        $this->assertSame(100, $cases['1-12']['percent']);
        $this->assertSame(50, $cases['1-14']['percent']);
        $this->assertSame(0, $cases['1-16']['percent']);
        $this->assertArrayNotHasKey('1-13', $cases->all());
    }

    public function test_un_praticien_absent_n_a_pas_de_minutes_ouvertes(): void
    {
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        Absence::factory()->create(['employe_id' => $ligne->employe_id, 'debut_le' => '2026-11-02', 'fin_le' => '2026-11-02']);

        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-02'])->assertOk()
            ->assertJsonPath('data.indicators.occupancy.open_minutes', 0)
            ->assertJsonPath('data.indicators.occupancy.percent', null);
    }

    public function test_une_autre_organisation_reste_invisible(): void
    {
        $etrangere = Clinique::factory()->create(['nom' => 'Ailleurs']);
        $this->payee('2026-11-02 10:00:00', 100.00);
        $autre = Facture::factory()->payee(MoyenPaiement::Tpe)->create([
            'clinique_id' => $etrangere->id,
            'emise_le' => CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'),
            'payee_le' => CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'),
        ]);
        FactureLigne::factory()->create(['facture_id' => $autre->id, 'quantite' => 1, 'prix_chf' => 9999, 'type' => TypeLigneFacture::Acte, 'libelle' => 'Secret']);
        $dueAilleurs = Facture::factory()->create(['clinique_id' => $etrangere->id]);
        FactureLigne::factory()->create(['facture_id' => $dueAilleurs->id, 'quantite' => 1, 'prix_chf' => 777]);
        $ligneAilleurs = LignePlanning::factory()->create(['clinique_id' => $etrangere->id, 'nom' => 'Praticien ailleurs']);

        $reponse = $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04'])->assertOk();
        $reponse->assertJsonPath('data.indicators.revenue.value', 100)
            ->assertJsonPath('data.indicators.due.total', 0)
            ->assertJsonPath('data.indicators.acts.count', 1)
            ->assertJsonMissing(['label' => 'Ailleurs'])
            ->assertJsonMissing(['name' => 'Praticien ailleurs'])
            ->assertJsonMissing(['label' => 'Secret']);
        $this->assertSame(100, (int) collect($reponse->json('data.history.points'))->sum('revenue'));
        $this->assertNotContains($ligneAilleurs->id, collect($reponse->json('data.occupancy.practitioners'))->pluck('lane_id'));

        // La clinique d'une autre organisation ne se demande pas
        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04', 'clinic_id' => $etrangere->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinic_id');
    }

    public function test_un_role_sans_la_caisse_est_refuse(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'assistant');

        $this->getJson('/api/v1/facturation/statistics?from=2026-11-02&to=2026-11-04', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_une_periode_absurde_est_refusee(): void
    {
        $this->stats([])->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->stats(['from' => '2026-11-04', 'to' => '2026-11-02'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-05'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->stats(['from' => '1999-12-31', 'to' => '2026-11-04'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->stats(['from' => '2016-11-03', 'to' => '2026-11-04'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->stats(['from' => '2026-11-02', 'to' => '2026-11-04', 'compare' => 'hier'])->assertUnprocessable()->assertJsonValidationErrors('compare');
        $this->stats(['from' => '02.11.2026', 'to' => '2026-11-04'])->assertUnprocessable()->assertJsonValidationErrors('from');
        // Dix ans tout juste : accepté
        $this->stats(['from' => '2016-11-05', 'to' => '2026-11-04'])->assertOk();
    }

    public function test_le_nombre_de_requetes_ne_grandit_pas_avec_les_donnees(): void
    {
        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->stats(['from' => '2026-10-01', 'to' => '2026-11-04', 'compare' => 'previous'])->assertOk();
            // Sanctum note l'usage du jeton une fois de temps en temps : hors du compte
            $nombre = collect(DB::getQueryLog())->reject(fn (array $requete): bool => str_contains($requete['query'], 'personal_access_tokens'))->count();
            DB::disableQueryLog();

            return $nombre;
        };

        // Dix ans par mois, l'histoire comprise (lecture en flux, vérification du 06.11.2026)
        $compterDixAns = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->stats(['from' => '2016-11-05', 'to' => '2026-11-04', 'compare' => 'last_year'])->assertOk();
            $nombre = collect(DB::getQueryLog())->reject(fn (array $requete): bool => str_contains($requete['query'], 'personal_access_tokens'))->count();
            DB::disableQueryLog();

            return $nombre;
        };

        $creer = function (int $combien): void {
            // Le contexte de la requête précédente survit dans les tests : on repart neutre
            app(ContexteClinique::class)->oublier();
            for ($i = 0; $i < $combien; $i++) {
                $ligne = LignePlanning::factory()->create(['clinique_id' => $i % 2 ? $this->clinique->id : $this->cliniqueSoeur->id]);
                $this->payee('2026-10-'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT).' 10:00:00', 50 + $i, MoyenPaiement::Twint, ['ligne_planning_id' => $ligne->id, 'clinique_id' => $ligne->clinique_id, 'species' => $i % 2 ? 'CT' : 'LP']);
                RendezVous::factory()->create(['clinique_id' => $ligne->clinique_id, 'ligne_planning_id' => $ligne->id, 'starts_at' => CarbonImmutable::parse('2026-10-'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT).' 09:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-10-'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT).' 09:30:00', 'UTC')]);
                $due = Facture::factory()->create(['clinique_id' => $ligne->clinique_id, 'ligne_planning_id' => $ligne->id]);
                FactureLigne::factory()->create(['facture_id' => $due->id, 'quantite' => 1, 'prix_chf' => 10]);
            }
        };

        $creer(2);
        $avant = $compter();
        $avantDixAns = $compterDixAns();
        $creer(8);
        $apres = $compter();

        $this->assertSame($avant, $apres);
        $this->assertSame($avantDixAns, $compterDixAns());
    }
}
