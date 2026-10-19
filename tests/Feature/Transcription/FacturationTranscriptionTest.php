<?php

namespace Tests\Feature\Transcription;

use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Models\Clinique;
use App\Models\EnregistrementConsultation;
use App\Models\FacturationTranscription;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\PartieEnregistrement;
use App\Models\RendezVous;
use App\Paiements\PasserelleAbonnement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Facturation des minutes de transcription : le mois écoulé (heure
 * suisse), total arrondi à la minute, 5 centimes HT la minute ; ligne
 * ajoutée à la prochaine facture de l'abonnement, ou facture à part sans
 * abonnement ; jamais deux fois le même mois.
 */
class FacturationTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private PasserelleAbonnementFactice $stripe;

    private Clinique $clinique;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PasserelleAbonnement::class, $this->stripe = new PasserelleAbonnementFactice);
        $this->clinique = Clinique::factory()->create();
        $this->organisation = $this->clinique->societe->organisation;
    }

    public function test_le_mois_ecoule_s_ajoute_a_la_prochaine_facture_de_l_abonnement(): void
    {
        $this->organisation->forceFill([
            'stripe_client_id' => 'cus_1', 'stripe_moyen_paiement_id' => 'pm_1',
            'stripe_abonnement_id' => 'sub_1', 'statut_abonnement' => 'actif',
        ])->save();

        $this->transcrite(1500, '2026-09-01 00:10');
        $this->transcrite(1000, '2026-09-30 23:50');
        // Le 1er octobre à 0 h 30 en Suisse : 22 h 30 le 30 septembre en UTC, mais octobre
        $this->transcrite(600, '2026-10-01 00:30');

        $this->artisan('transcriptions:facturer', ['--mois' => '2026-09'])->assertSuccessful();
        $this->artisan('transcriptions:facturer', ['--mois' => '2026-09'])->assertSuccessful();

        $this->assertCount(1, $this->stripe->lignesEnAttente);
        $ligne = array_values($this->stripe->lignesEnAttente)[0];
        // 2500 s : 42 minutes à 5 centimes
        $this->assertSame(210, $ligne['amount']);
        $this->assertSame('sub_1', $ligne['subscription']);
        $this->assertSame('Transcriptions des consultations, septembre 2026 : 42 min', $ligne['description']);

        $facturation = FacturationTranscription::query()->sole();
        $this->assertSame([2500, 42, 210], [$facturation->secondes, $facturation->minutes, $facturation->montant_centimes]);
    }

    public function test_sans_abonnement_une_facture_a_part_est_prelevee(): void
    {
        $this->organisation->forceFill(['stripe_client_id' => 'cus_1', 'stripe_moyen_paiement_id' => 'pm_1'])->save();
        $this->transcrite(59, '2026-09-15 10:00');

        $this->artisan('transcriptions:facturer', ['--mois' => '2026-09'])->assertSuccessful();

        $this->assertCount(0, $this->stripe->lignesEnAttente);
        $facture = OrganisationFacture::query()->sole();
        $this->assertSame('Transcriptions des consultations, septembre 2026 : 1 min', $facture->libelle);
        $this->assertSame('payee', $facture->statut);
    }

    public function test_par_defaut_le_mois_ecoule_et_rien_sans_moyen_de_facturer(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 03:00', 'Europe/Zurich'));
        $this->transcrite(120, '2026-09-20 10:00');

        $this->artisan('transcriptions:facturer')->assertSuccessful();

        $this->assertSame(0, FacturationTranscription::query()->count());
        $this->assertSame([], $this->stripe->factures);
    }

    /** Une partie transcrite à l'heure suisse donnée. */
    private function transcrite(int $secondes, string $heureSuisse): void
    {
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id,
            'clinique_id' => $this->clinique->id,
            'rendez_vous_id' => RendezVous::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'statut' => StatutEnregistrement::Valide,
        ]);
        PartieEnregistrement::query()->create([
            'enregistrement_consultation_id' => $enregistrement->id,
            'numero' => 1,
            'statut' => StatutPartie::Transcrite,
            'format' => 'audio/mp4',
            'morceaux' => 1,
            'duree_secondes' => $secondes,
            'transcrite_le' => CarbonImmutable::parse($heureSuisse, 'Europe/Zurich')->utc(),
        ]);
    }
}
