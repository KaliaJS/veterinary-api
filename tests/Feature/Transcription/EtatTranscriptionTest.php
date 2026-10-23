<?php

namespace Tests\Feature\Transcription;

use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\EnregistrementConsultation;
use App\Models\Organisation;
use App\Models\PartieEnregistrement;
use App\Models\RendezVous;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * État de la transcription avant d'enregistrer (`GET /transcription`) :
 * disponible ou non avec le motif lisible (facture impayée, plafond
 * atteint), consommation de la seule organisation du jeton sur sa
 * période, règles de l'app ; réservé au domaine patients.
 */
class EtatTranscriptionTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Organisation $organisation;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->organisation = $this->clinique->societe->organisation;
        $this->organisation->forceFill([
            'stripe_client_id' => 'cus_1', 'stripe_moyen_paiement_id' => 'pm_1',
            'statut_abonnement' => 'actif',
            'periode_debut' => aujourdhui()->subDays(5),
            'periode_fin' => aujourdhui()->addDays(25),
        ])->save();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_sans_enregistrement_la_transcription_est_disponible_et_rien_n_est_consomme(): void
    {
        $this->getJson('/api/v1/transcription', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.motif', null)
            ->assertJsonPath('data.periode_debut', aujourdhui()->subDays(5)->toDateString())
            ->assertJsonPath('data.minutes_periode', 0)
            ->assertJsonPath('data.montant_periode', 0)
            ->assertJsonPath('data.plafond', 200)
            ->assertJsonPath('data.tarif_minute', 0.05)
            ->assertJsonPath('data.regles.duree_max', 3600)
            ->assertJsonPath('data.regles.silence_alerte', 300)
            ->assertJsonPath('data.regles.morceau_max_ko', 1024);
    }

    public function test_seules_les_minutes_de_l_organisation_et_de_sa_periode_comptent(): void
    {
        $this->transcrit($this->clinique, 125);
        // Avant le début de la période : hors du décompte
        $this->transcrit($this->clinique, 600, aujourdhui()->subDays(10));
        // Une autre organisation : jamais comptée ici
        $this->transcrit(Clinique::factory()->create(), 3000);

        $this->getJson('/api/v1/transcription', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.minutes_periode', 3)
            ->assertJsonPath('data.montant_periode', 0.15);
    }

    public function test_une_facture_impayee_rend_la_transcription_indisponible_avec_son_motif(): void
    {
        $this->organisation->forceFill(['statut_abonnement' => 'impaye'])->save();

        $this->getJson('/api/v1/transcription', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.disponible', false)
            ->assertJsonPath('data.motif', 'Une facture de votre abonnement attend son règlement : réglez-la dans les réglages de facturation pour utiliser la transcription.');
    }

    public function test_le_plafond_atteint_rend_la_transcription_indisponible(): void
    {
        // 3 CHF de plafond ; 61 minutes à 5 centimes : 3,05 CHF
        $this->organisation->forceFill(['plafond_transcription' => 300])->save();
        $this->transcrit($this->clinique, 3650);

        $this->getJson('/api/v1/transcription', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.disponible', false)
            ->assertJsonPath('data.minutes_periode', 61)
            ->assertJsonPath('data.montant_periode', 3.05)
            ->assertJsonPath('data.plafond', 3)
            ->assertJsonPath('data.motif', 'Le plafond de transcription de votre organisation est atteint pour cette période. Un administrateur peut le relever dans les réglages de l’organisation.');
    }

    public function test_sans_le_domaine_patients_l_etat_est_refuse(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Patients,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/transcription', $this->enTetes($jeton))->assertForbidden();
    }

    private function transcrit(Clinique $clinique, int $secondes, ?CarbonInterface $le = null): void
    {
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()->create([
            'organisation_id' => $clinique->societe->organisation_id,
            'clinique_id' => $clinique->id,
            'rendez_vous_id' => RendezVous::factory()->create(['clinique_id' => $clinique->id])->id,
            'statut' => StatutEnregistrement::Pret,
        ]);
        PartieEnregistrement::query()->create([
            'enregistrement_consultation_id' => $enregistrement->id,
            'numero' => 1,
            'statut' => StatutPartie::Transcrite,
            'format' => 'audio/mp4',
            'morceaux' => 1,
            'duree_secondes' => $secondes,
            'transcrite_le' => $le ?? now(),
        ]);
    }
}
