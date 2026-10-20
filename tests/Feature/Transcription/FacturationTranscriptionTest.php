<?php

namespace Tests\Feature\Transcription;

use App\Enums\FormuleSaas;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Models\Clinique;
use App\Models\EnregistrementConsultation;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\PartieEnregistrement;
use App\Models\RendezVous;
use App\Paiements\PasserelleAbonnement;
use App\Services\Abonnements\AbonnementSaas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Les minutes de transcription se facturent par période d'abonnement : sur
 * la facture du renouvellement (total arrondi à la minute, 5 centimes la
 * minute, sans TVA), sur celle d'un changement de formule qui clôt la
 * période, ou sur une facture finale quand l'abonnement prend fin ; jamais
 * deux fois la même période.
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
        $client = $this->stripe->creerClient('Clinique', null, []);
        $this->organisation->forceFill([
            'stripe_client_id' => $client,
            'stripe_moyen_paiement_id' => $this->stripe->carteConfirmee($client),
        ])->save();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        app(AbonnementSaas::class)->souscrire($this->organisation, FormuleSaas::Cabinet);
        $this->organisation->refresh();
    }

    public function test_les_minutes_de_la_periode_s_ajoutent_au_renouvellement_une_seule_fois(): void
    {
        $this->transcrite(1500, '2026-10-20 11:00');
        $this->transcrite(1000, '2026-11-19 23:50');
        // Le jour du renouvellement : période suivante
        $this->transcrite(600, '2026-11-20 00:30');

        $this->travelTo(CarbonImmutable::parse('2026-11-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $facture = OrganisationFacture::query()->latest('id')->firstOrFail();
        // 2500 s : 42 minutes à 5 centimes
        $this->assertSame(24900 + 210, $this->stripe->dernierPrelevement()['centimes']);
        $this->assertSame('Transcription des consultations, du 20.10.2026 au 19.11.2026 : 42 min', $facture->lignes[1]['libelle']);
        $this->assertSame(2.1, $facture->lignes[1]['montant']);
        $this->assertDatabaseHas('facturations_transcription', ['organisation_id' => $this->organisation->id, 'minutes' => 42, 'organisation_facture_id' => $facture->id]);

        // La période suivante porte les 600 s du 20 novembre
        $this->travelTo(CarbonImmutable::parse('2026-12-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame(24900 + 50, $this->stripe->dernierPrelevement()['centimes']);
    }

    public function test_un_changement_de_formule_clot_la_periode_et_facture_ses_minutes(): void
    {
        $this->transcrite(59, '2026-10-25 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'Europe/Zurich'));

        app(AbonnementSaas::class)->souscrire($this->organisation->refresh(), FormuleSaas::Hopital);

        $lignes = OrganisationFacture::query()->latest('id')->firstOrFail()->lignes;
        $this->assertSame('Transcription des consultations, du 20.10.2026 au 29.10.2026 : 1 min', end($lignes)['libelle']);

        // Le renouvellement suivant ne la refacture pas
        $this->travelTo(CarbonImmutable::parse('2026-11-30 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame(99900, $this->stripe->dernierPrelevement()['centimes']);
    }

    public function test_la_fin_de_l_abonnement_facture_les_dernieres_minutes(): void
    {
        app(AbonnementSaas::class)->resilier($this->organisation);
        $this->transcrite(120, '2026-11-02 10:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame(10, $this->stripe->dernierPrelevement()['centimes']);
        $this->assertDatabaseHas('organisations', ['id' => $this->organisation->id, 'statut_abonnement' => 'resilie']);
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
