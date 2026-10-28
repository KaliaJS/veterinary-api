<?php

namespace Tests\Feature\TempsReel;

use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Events\DiagnosticMisAJour;
use App\Events\EnregistrementMisAJour;
use App\Events\FactureAbonnementMisAJour;
use App\Events\FacturePlusMisAJour;
use App\Jobs\RedigerCompteRendu;
use App\Jobs\SuggererDiagnostic;
use App\Jobs\TranscrirePartie;
use App\Models\Clinique;
use App\Models\EnregistrementConsultation;
use App\Models\FactureProprietaire;
use App\Models\OrganisationFacture;
use App\Models\PartieEnregistrement;
use App\Models\RendezVous;
use App\Models\SuggestionDiagnostique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Suivis en temps réel (règle de Jim, 28.10.2026) : transcription,
 * diagnostic, factures d'abonnement de l'organisation et factures Plus du
 * propriétaire ; chaque écran apprend le changement par Reverb au lieu de
 * relire toutes les trois ou cinq secondes.
 */
class SuivisTempsReelTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
    }

    private function avecReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-essai',
            'broadcasting.connections.reverb.secret' => 'secret-essai',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');
    }

    public function test_l_avancement_d_un_enregistrement_est_diffuse_court_a_la_clinique(): void
    {
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        $cree = EnregistrementConsultation::query()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rendezVous->id]);
        Event::fake([EnregistrementMisAJour::class]);
        // Relu : un changement, pas la création
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()->findOrFail($cree->id);

        $enregistrement->forceFill(['statut' => StatutEnregistrement::Pret])->save();

        Event::assertDispatched(EnregistrementMisAJour::class, fn (EnregistrementMisAJour $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id
            && $e->broadcastWith() === ['id' => $enregistrement->id, 'rendez_vous_id' => $rendezVous->id, 'statut' => StatutEnregistrement::Pret->value]);
    }

    public function test_un_diagnostic_rendu_est_diffuse_court_a_la_clinique(): void
    {
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        $creee = SuggestionDiagnostique::query()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rendezVous->id]);
        Event::fake([DiagnosticMisAJour::class]);
        $suggestion = SuggestionDiagnostique::withoutGlobalScopes()->findOrFail($creee->id);

        $suggestion->forceFill(['statut' => 'prete'])->save();

        Event::assertDispatched(DiagnosticMisAJour::class, fn (DiagnosticMisAJour $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id
            && $e->broadcastWith() === ['id' => $suggestion->id, 'rendez_vous_id' => $rendezVous->id, 'statut' => 'prete']);
    }

    public function test_une_facture_d_abonnement_qui_change_previent_la_facturation_de_l_organisation(): void
    {
        $facture = OrganisationFacture::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        Event::fake([FactureAbonnementMisAJour::class]);

        $facture->forceFill(['statut' => 'payee', 'paye_le' => now()])->save();

        Event::assertDispatched(FactureAbonnementMisAJour::class, fn (FactureAbonnementMisAJour $e): bool => $e->broadcastOn()[0]->name === 'private-organisation.'.$this->clinique->societe->organisation_id.'.facturation'
            && $e->broadcastWith() === ['id' => $facture->id]);
    }

    public function test_une_facture_plus_qui_change_previent_son_proprietaire(): void
    {
        ['user' => $user] = $this->proprietaireAvecJeton();
        $facture = FactureProprietaire::query()->forceCreate(['user_id' => $user->id, 'numero' => 'P-2026-001', 'emise_le' => now(), 'libelle' => 'Plus', 'montant' => 12, 'statut' => 'a_payer']);
        Event::fake([FacturePlusMisAJour::class]);

        $facture->forceFill(['statut' => 'payee'])->save();

        Event::assertDispatched(FacturePlusMisAJour::class, fn (FacturePlusMisAJour $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$user->id
            && $e->broadcastWith() === ['id' => $facture->id]);
    }

    public function test_le_proprietaire_n_ouvre_que_son_propre_canal(): void
    {
        $this->avecReverb();
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        ['user' => $autre] = $this->proprietaireAvecJeton();
        $auth = fn (string $canal) => $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $canal], $this->enTetes($jeton));

        $auth('private-utilisateur.'.$user->id)->assertOk();
        $auth('private-utilisateur.'.$autre->id)->assertForbidden();
        $auth('private-clinique.'.$this->clinique->id)->assertForbidden();
    }

    public function test_la_facturation_de_l_organisation_s_ouvre_meme_fermee_avec_le_droit_organisation(): void
    {
        $this->avecReverb();
        ['jeton' => $admin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $employe] = $this->employeAvecJeton($this->clinique);
        $this->clinique->societe->organisation->forceFill(['statut_abonnement' => null])->save();
        $organisation = $this->clinique->societe->organisation_id;
        $auth = fn (string $jeton, string $canal) => $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $canal], $this->enTetes($jeton));

        $auth($admin, 'private-organisation.'.$organisation.'.facturation')->assertOk();
        $auth($employe, 'private-organisation.'.$organisation.'.facturation')->assertForbidden();
        // Organisation fermée : le canal de la clinique reste fermé
        $auth($admin, 'private-clinique.'.$this->clinique->id)->assertForbidden();
    }

    /* ---------- Troisième passe (28.10.2026) : chaque changement d'état est annoncé ---------- */

    private function enregistrement(StatutEnregistrement $statut, array $attributs = []): EnregistrementConsultation
    {
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);

        return EnregistrementConsultation::query()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rendezVous->id, 'statut' => $statut, ...$attributs]);
    }

    private function annonce(EnregistrementConsultation $enregistrement, StatutEnregistrement $statut): void
    {
        Event::assertDispatched(EnregistrementMisAJour::class, fn (EnregistrementMisAJour $e): bool => $e->broadcastWith()['id'] === $enregistrement->id && $e->broadcastWith()['statut'] === $statut->value);
    }

    public function test_l_echec_de_la_redaction_est_annonce(): void
    {
        $enregistrement = $this->enregistrement(StatutEnregistrement::Redaction);
        Event::fake([EnregistrementMisAJour::class]);

        (new RedigerCompteRendu($enregistrement->id))->failed(null);

        $this->annonce($enregistrement, StatutEnregistrement::Erreur);
    }

    public function test_le_passage_en_redaction_et_le_refus_sont_annonces(): void
    {
        Bus::fake();
        $enCours = $this->enregistrement(StatutEnregistrement::Transcription, ['parties_attendues' => 1]);
        PartieEnregistrement::query()->create(['enregistrement_consultation_id' => $enCours->id, 'numero' => 1, 'statut' => StatutPartie::Transcrite, 'format' => 'audio/mp4', 'morceaux' => 1]);
        $refuse = $this->enregistrement(StatutEnregistrement::Transcription, ['parties_attendues' => 1, 'redactions' => (int) config('transcription.redactions_max')]);
        PartieEnregistrement::query()->create(['enregistrement_consultation_id' => $refuse->id, 'numero' => 1, 'statut' => StatutPartie::Transcrite, 'format' => 'audio/mp4', 'morceaux' => 1]);
        Event::fake([EnregistrementMisAJour::class]);

        RedigerCompteRendu::siPret($enCours);
        RedigerCompteRendu::siPret($refuse);

        $this->annonce($enCours, StatutEnregistrement::Redaction);
        $this->annonce($refuse, StatutEnregistrement::Erreur);
    }

    public function test_l_echec_d_une_transcription_apres_terminer_est_annonce(): void
    {
        $enregistrement = $this->enregistrement(StatutEnregistrement::Transcription, ['parties_attendues' => 1]);
        $partie = PartieEnregistrement::query()->create(['enregistrement_consultation_id' => $enregistrement->id, 'numero' => 1, 'statut' => StatutPartie::Recue, 'format' => 'audio/mp4', 'morceaux' => 1]);
        Event::fake([EnregistrementMisAJour::class]);

        (new TranscrirePartie($partie->id))->failed(null);

        $this->annonce($enregistrement, StatutEnregistrement::Erreur);
    }

    public function test_l_echec_d_un_diagnostic_est_annonce(): void
    {
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        $suggestion = SuggestionDiagnostique::query()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rendezVous->id]);
        Event::fake([DiagnosticMisAJour::class]);

        (new SuggererDiagnostic($suggestion->id))->failed(null);

        Event::assertDispatched(DiagnosticMisAJour::class, fn (DiagnosticMisAJour $e): bool => $e->broadcastWith()['id'] === $suggestion->id && $e->broadcastWith()['statut'] === 'erreur');
    }
}
