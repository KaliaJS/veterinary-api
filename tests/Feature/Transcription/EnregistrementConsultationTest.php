<?php

namespace Tests\Feature\Transcription;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Mail\AlerteTranscription;
use App\Mail\PlafondTranscriptionOrganisation;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\Employe;
use App\Models\EnregistrementConsultation;
use App\Models\Organisation;
use App\Models\PartieEnregistrement;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Role;
use App\Services\Transcription\Redacteur;
use App\Services\Transcription\RedactionImpossible;
use App\Services\Transcription\Transcripteur;
use App\Services\Transcription\TranscriptionImpossible;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\RedacteurFactice;
use Tests\Doubles\TranscripteurFactice;
use Tests\TestCase;

/**
 * Transcription des consultations : démarrer, envoyer l'audio par
 * morceaux (dans le désordre, en double), transcrire puis effacer l'audio,
 * terminer, brouillon SOAP, validation par le compte rendu ; plafonds,
 * erreurs relancées, isolation d'organisation, réglages.
 */
class EnregistrementConsultationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private TranscripteurFactice $transcripteur;

    private RedacteurFactice $redacteur;

    private Clinique $clinique;

    private Organisation $organisation;

    private RendezVous $rendezVous;

    private Employe $employe;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(Transcripteur::class, $this->transcripteur = new TranscripteurFactice);
        $this->app->instance(Redacteur::class, $this->redacteur = new RedacteurFactice);

        $this->clinique = Clinique::factory()->create();
        $this->organisation = $this->clinique->societe->organisation;
        $this->organisation->forceFill([
            'stripe_client_id' => 'cus_1', 'stripe_moyen_paiement_id' => 'pm_1',
            'stripe_abonnement_id' => 'sub_1', 'statut_abonnement' => 'actif',
        ])->save();

        $patient = Patient::factory()->create([
            'client_id' => Client::factory()->create(['organisation_id' => $this->organisation->id])->id,
            'nom' => 'Pacha',
            'race' => 'Bouvier bernois',
        ]);
        $this->rendezVous = RendezVous::factory()->pourPatient($patient)->create([
            'clinique_id' => $this->clinique->id,
            'reason' => 'Boiterie',
        ]);

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_deux_parties_envoyees_puis_terminees_donnent_un_brouillon_et_l_audio_est_efface(): void
    {
        $id = $this->demarrer()->assertCreated()->assertJsonPath('data.statut', 'enregistrement')->json('data.id');

        // Morceaux dans le désordre : l'assemblage suit leur rang
        $this->morceau($id, 1, 1, 3, 'BB')->assertOk()->assertJsonPath('data.morceaux_recus', [1]);
        $this->morceau($id, 1, 0, 3, 'AA')->assertOk()->assertJsonPath('data.statut', 'envoi');
        $this->morceau($id, 1, 2, 3, 'CC')->assertOk()->assertJsonPath('data.statut', 'transcrite');
        $this->envoyer($id, 2, 'DEUXIEME');

        $this->assertSame(['AABBCC', 'DEUXIEME'], array_column($this->transcripteur->appels, 'contenu'));
        $this->assertSame([], Storage::disk('local')->allFiles('transcriptions'));
        $this->assertContains('Bouvier bernois', $this->transcripteur->appels[0]['termes']);

        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 2], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'pret')
            ->assertJsonPath('data.brouillon.s', 'Boiterie depuis 3 jours, surtout le matin.')
            ->assertJsonPath('data.brouillon.doutes.0.passage', 'genou droit')
            ->assertJsonPath('data.brouillon.doutes.0.raison', 'Côté du genou à confirmer.');

        $this->assertCount(1, $this->redacteur->appels);
        $this->assertSame([1, 1, 2, 2], array_column($this->redacteur->appels[0]['segments'], 'partie'));
        $this->assertSame('Pacha', $this->redacteur->appels[0]['contexte']['Animal']);
        $this->assertSame('Boiterie', $this->redacteur->appels[0]['contexte']['Motif du rendez-vous']);

        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}/enregistrement", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.duree_secondes', 192)
            ->assertJsonCount(4, 'data.transcription')
            ->assertJsonPath('data.transcription.2.partie', 2)
            ->assertJsonPath('data.transcription.2.texte', 'Il boite depuis trois jours, surtout le matin.');

        // Le texte est chiffré en base
        $brut = (string) PartieEnregistrement::query()->toBase()->value('segments');
        $this->assertStringNotContainsString('boite', $brut);
    }

    public function test_terminer_avant_la_fin_de_l_envoi_attend_la_derniere_partie(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'UN');

        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 2], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'transcription');
        $this->assertCount(0, $this->redacteur->appels);

        $this->envoyer($id, 2, 'DEUX');

        $this->assertSame(StatutEnregistrement::Pret, EnregistrementConsultation::find($id)->statut);
        $this->assertCount(1, $this->redacteur->appels);
    }

    public function test_un_morceau_renvoye_ne_change_rien(): void
    {
        $id = $this->demarrer()->json('data.id');

        $this->morceau($id, 1, 0, 2, 'XX')->assertOk();
        $this->morceau($id, 1, 0, 2, 'AA')->assertOk()->assertJsonPath('data.morceaux_recus', [0]);
        $this->morceau($id, 1, 1, 2, 'BB')->assertOk()->assertJsonPath('data.statut', 'transcrite');
        // Renvoi tardif après la transcription : ignoré
        $this->morceau($id, 1, 1, 2, 'BB')->assertOk()->assertJsonPath('data.statut', 'transcrite');

        $this->assertSame(['AABB'], array_column($this->transcripteur->appels, 'contenu'));
        $this->morceau($id, 2, 3, 2, 'ZZ')->assertUnprocessable()->assertJsonValidationErrors('morceau');
    }

    public function test_sans_abonnement_en_cours_ou_au_plafond_aucun_enregistrement_ne_commence_mais_celui_en_cours_continue(): void
    {
        // Abonnement terminé : toute l'application clinique est fermée
        $this->organisation->forceFill(['statut_abonnement' => 'resilie'])->save();
        $this->demarrer()->assertForbidden()->assertJsonPath('code', 'abonnement_requis');

        $this->organisation->forceFill(['statut_abonnement' => 'impaye'])->save();
        $this->demarrer()->assertUnprocessable()->assertJsonPath('errors.transcription.0', 'Une facture de votre abonnement attend son règlement : réglez-la dans les réglages de facturation pour utiliser la transcription.');

        $this->organisation->forceFill(['statut_abonnement' => 'actif', 'plafond_transcription' => 300])->save();
        $enCours = $this->enregistrementTranscrit(4000);
        // 4000 s, soit 67 min à 5 centimes : 3,35 CHF, au-dessus de 3 CHF
        $enCours->forceFill(['statut' => StatutEnregistrement::Enregistrement])->save();
        $this->demarrer()->assertOk()->assertJsonPath('data.id', $enCours->id);

        $enCours->forceFill(['statut' => StatutEnregistrement::Valide])->save();
        $this->demarrer()
            ->assertUnprocessable()
            ->assertJsonPath('errors.transcription.0', 'Le plafond de transcription de votre organisation est atteint pour cette période. Un administrateur peut le relever dans les réglages de l’organisation.');
    }

    public function test_le_budget_deepgram_vide_est_illimite_sinon_il_previent_puis_bloque(): void
    {
        Mail::fake();
        config(['transcription.alerte_email' => 'alertes@veterinary.test', 'transcription.cout_minute_deepgram_usd' => 0.0065]);

        // Budget vide : illimité, aucune alerte
        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'AUDIO');
        Mail::assertNothingSent();

        config(['transcription.budget_deepgram_usd' => 0.02]);
        $this->envoyer($id, 2, 'AUDIO');

        // 2 × 95,2 s à 0,0065 $ la minute : environ 0,02 $, le budget est atteint
        Mail::assertSent(AlerteTranscription::class, fn (AlerteTranscription $mail) => $mail->seuil === 100 && $mail->minutes === 4 && $mail->depense === 0.02);

        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 2], $this->enTetes($this->jeton))->assertOk();
        $this->putJson("/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu", ['s' => 'Relu.', 'enregistrement_id' => $id], $this->enTetes($this->jeton))->assertSuccessful();

        $this->demarrer()->assertUnprocessable()->assertJsonPath('errors.transcription.0', 'La transcription est momentanément indisponible. Réessayez plus tard.');
    }

    public function test_les_administrateurs_sont_prevenus_au_plafond_de_l_organisation_une_fois(): void
    {
        Mail::fake();
        $this->employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->organisation->id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)
            ->value('id')]);
        ['user' => $autre] = $this->employeAvecJeton($this->clinique);
        // 95,2 s : 2 minutes à 5 centimes, le plafond de 0,10 CHF est atteint
        $this->organisation->forceFill(['plafond_transcription' => 10])->save();

        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'UN');
        $this->envoyer($id, 2, 'DEUX');

        Mail::assertSent(PlafondTranscriptionOrganisation::class, 1);
        Mail::assertSent(PlafondTranscriptionOrganisation::class, fn ($mail) => $mail->seuil === 100
            && $mail->hasTo($this->employe->user->email)
            && ! $mail->hasTo($autre->email));
    }

    public function test_un_refus_de_transcription_met_l_enregistrement_en_erreur_et_se_relance(): void
    {
        $this->transcripteur->refus = new TranscriptionImpossible('Audio illisible.');
        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'ABIME');

        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'erreur')
            ->assertJsonPath('data.erreur', 'transcription');
        // L'audio reste pour la relance
        $this->assertCount(1, Storage::disk('local')->allFiles('transcriptions'));

        $this->transcripteur->refus = null;
        $this->postJson("/api/v1/enregistrements/{$id}/relancer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'pret');
        $this->assertSame([], Storage::disk('local')->allFiles('transcriptions'));
    }

    public function test_une_redaction_impossible_se_relance(): void
    {
        $this->redacteur->erreur = new RedactionImpossible('Refus.');
        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'AUDIO');

        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))
            ->assertJsonPath('data.statut', 'erreur')
            ->assertJsonPath('data.erreur', 'redaction');

        $this->redacteur->erreur = null;
        $this->postJson("/api/v1/enregistrements/{$id}/relancer", [], $this->enTetes($this->jeton))
            ->assertJsonPath('data.statut', 'pret');
    }

    public function test_le_compte_rendu_enregistre_valide_le_brouillon_puis_un_nouvel_enregistrement_peut_commencer(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'AUDIO');
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertOk();

        $this->putJson("/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu", [
            's' => 'Boiterie depuis 3 jours, corrigé.',
            'o' => 'Chaleur au genou droit.',
            'a' => null,
            'p' => 'Repos 10 jours.',
            'enregistrement_id' => $id,
        ], $this->enTetes($this->jeton))->assertSuccessful();

        $this->assertSame('Boiterie depuis 3 jours, corrigé.', CompteRendu::query()->value('s'));
        $this->assertSame(StatutEnregistrement::Valide, EnregistrementConsultation::find($id)->statut);

        // Pause puis reprise avant validation : la même ; après validation : un nouvel enregistrement
        $this->assertNotSame($id, $this->demarrer()->assertCreated()->json('data.id'));
    }

    public function test_reprendre_apres_le_brouillon_ajoute_une_partie(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'UN');
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertJsonPath('data.statut', 'pret');

        $this->demarrer()
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.statut', 'enregistrement')
            ->assertJsonPath('data.prochaine_partie', 2)
            ->assertJsonPath('data.brouillon', null);

        $this->envoyer($id, 2, 'DEUX');
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 2], $this->enTetes($this->jeton))->assertJsonPath('data.statut', 'pret');
        $this->assertCount(4, $this->redacteur->appels[1]['segments']);
    }

    public function test_l_abandon_efface_audio_et_texte(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->morceau($id, 1, 0, 2, 'AA')->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('transcriptions'));

        $this->deleteJson("/api/v1/enregistrements/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();

        $this->assertSame([], Storage::disk('local')->allFiles('transcriptions'));
        $this->assertSame(0, PartieEnregistrement::query()->count());
    }

    public function test_une_autre_organisation_ne_voit_ni_ne_complete_l_enregistrement(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->app->make(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());

        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}/enregistrement", $this->enTetes($autre))->assertNotFound();
        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 1, 'format' => 'audio/mp4', 'fichier' => UploadedFile::fake()->createWithContent('morceau', 'X'),
        ], [...$this->enTetes($autre), 'Accept' => 'application/json'])->assertNotFound();
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($autre))->assertNotFound();
    }

    public function test_etat_et_reglages_de_la_transcription(): void
    {
        $this->enregistrementTranscrit(150);

        $this->getJson('/api/v1/transcription', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.minutes_periode', 3)
            ->assertJsonPath('data.montant_periode', 0.15)
            ->assertJsonPath('data.plafond', 200)
            ->assertJsonPath('data.tarif_minute', 0.05)
            ->assertJsonPath('data.regles.duree_max', 3600)
            ->assertJsonPath('data.regles.silence_arret', 600);

        // Les réglages sont au domaine organisation (administrateur)
        $reglages = ['plafond' => 150.5, 'termes' => [' Beloc Zok ', '', 'Metacam']];
        $this->putJson('/api/v1/organisation/transcription', $reglages, $this->enTetes($this->jeton))->assertForbidden();
        $this->employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->organisation->id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)
            ->value('id')]);

        $this->putJson('/api/v1/organisation/transcription', $reglages, $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.plafond', 150.5)
            ->assertJsonPath('data.termes', ['Beloc Zok', 'Metacam']);
        $this->assertSame(15050, $this->organisation->refresh()->plafond_transcription);

        $this->putJson('/api/v1/organisation/transcription', ['plafond' => 100, 'termes' => ['Metacam', 'metacam']], $this->enTetes($this->jeton))
            ->assertJsonValidationErrors('termes.1');
    }

    private function demarrer(): TestResponse
    {
        return $this->postJson("/api/v1/rendez-vous/{$this->rendezVous->id}/enregistrement", [], $this->enTetes($this->jeton));
    }

    private function morceau(int $id, int $numero, int $index, int $total, string $octets): TestResponse
    {
        return $this->post("/api/v1/enregistrements/{$id}/parties/{$numero}", [
            'morceau' => $index,
            'morceaux' => $total,
            'format' => 'audio/mp4',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', $octets),
        ], [...$this->enTetes($this->jeton), 'Accept' => 'application/json']);
    }

    private function envoyer(int $id, int $numero, string $audio): void
    {
        $morceaux = str_split($audio, 3);
        foreach ($morceaux as $index => $octets) {
            $this->morceau($id, $numero, $index, count($morceaux), $octets)->assertOk();
        }
    }

    /** Un enregistrement déjà transcrit ce mois-ci, de la durée voulue. */
    private function enregistrementTranscrit(int $secondes): EnregistrementConsultation
    {
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id,
            'clinique_id' => $this->clinique->id,
            'rendez_vous_id' => $this->rendezVous->id,
            'statut' => StatutEnregistrement::Pret,
        ]);
        PartieEnregistrement::query()->create([
            'enregistrement_consultation_id' => $enregistrement->id,
            'numero' => 1,
            'statut' => StatutPartie::Transcrite,
            'format' => 'audio/mp4',
            'morceaux' => 1,
            'duree_secondes' => $secondes,
            'transcrite_le' => now(),
        ]);

        return $enregistrement;
    }
}
