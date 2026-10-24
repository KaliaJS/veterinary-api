<?php

namespace Tests\Feature\Transcription;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Jobs\TranscrirePartie;
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
use Illuminate\Support\Facades\Cache;
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

        Storage::fake('prive');
        $this->app->instance(Transcripteur::class, $this->transcripteur = new TranscripteurFactice);
        $this->app->instance(Redacteur::class, $this->redacteur = new RedacteurFactice);

        $this->clinique = Clinique::factory()->create();
        $this->organisation = $this->clinique->societe->organisation;
        $this->organisation->forceFill([
            'stripe_client_id' => 'cus_1', 'stripe_moyen_paiement_id' => 'pm_1',
            'statut_abonnement' => 'actif',
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
        $this->assertSame([], Storage::disk('prive')->allFiles('transcriptions'));
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

    public function test_une_partie_deja_en_cours_de_transcription_n_est_pas_transcrite_une_seconde_fois(): void
    {
        $id = $this->demarrer()->json('data.id');
        $partieId = fn (): int => (int) PartieEnregistrement::query()->value('id');

        // Un premier worker tient la partie (le fournisseur répond encore)
        $this->morceau($id, 1, 0, 2, 'AA')->assertOk();
        $verrou = Cache::lock('laravel-queue-overlap:'.TranscrirePartie::class.':transcription-partie:'.$partieId(), 1100);
        $this->assertTrue($verrou->get());

        // La partie complète repart dans la file : la reprise ne rappelle pas le fournisseur
        $this->morceau($id, 1, 1, 2, 'BB')->assertOk();
        $this->assertSame([], $this->transcripteur->appels);

        // Le premier worker libère la partie : la tâche reprise la transcrit une seule fois
        $verrou->release();
        TranscrirePartie::dispatchSync($partieId());
        TranscrirePartie::dispatchSync($partieId());
        $this->assertSame(['AABB'], array_column($this->transcripteur->appels, 'contenu'));
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
        $this->assertCount(1, Storage::disk('prive')->allFiles('transcriptions'));

        $this->transcripteur->refus = null;
        $this->postJson("/api/v1/enregistrements/{$id}/relancer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'pret');
        $this->assertSame([], Storage::disk('prive')->allFiles('transcriptions'));
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

    public function test_un_enregistrement_est_borne_en_parties_et_en_octets(): void
    {
        $id = $this->demarrer()->json('data.id');

        $this->morceau($id, (int) config('transcription.parties_max') + 1, 0, 1, 'AAA')
            ->assertUnprocessable()->assertJsonValidationErrors('numero');

        // Le premier morceau doit être de l'audio du format annoncé
        $this->morceauBrut($id, 1, 0, 2, '<?php echo 1;')->assertUnprocessable()->assertJsonValidationErrors('fichier');

        // Deux morceaux d'1 Mo au plus permis : une partie qui en annonce trois est refusée
        config(['transcription.octets_max_enregistrement' => 2 * 1024 * 1024]);
        $this->morceau($id, 1, 0, 3, 'AAA')->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->morceau($id, 1, 0, 2, 'AAA')->assertOk();
        $this->morceau($id, 2, 0, 1, 'BBB')->assertUnprocessable()->assertJsonValidationErrors('fichier');
    }

    public function test_les_redactions_sont_bornees_puis_la_relance_refusee(): void
    {
        config(['transcription.redactions_max' => 2]);
        $this->redacteur->erreur = new RedactionImpossible('Refus.');
        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'AUDIO');
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertJsonPath('data.erreur', 'redaction');

        // Deuxième et dernière rédaction : encore un refus
        $this->postJson("/api/v1/enregistrements/{$id}/relancer", [], $this->enTetes($this->jeton))->assertJsonPath('data.erreur', 'redaction');
        // Plus de rédaction : l'enregistrement le dit, la relance est refusée
        $this->postJson("/api/v1/enregistrements/{$id}/relancer", [], $this->enTetes($this->jeton))->assertJsonPath('data.erreur', 'redactions');
        $this->postJson("/api/v1/enregistrements/{$id}/relancer", [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors(['enregistrement' => 'Le compte rendu a déjà été rédigé plusieurs fois : écrivez-le à partir de la transcription.']);
        $this->assertCount(2, $this->redacteur->appels);
    }

    public function test_le_budget_anthropic_atteint_arrete_la_redaction(): void
    {
        config(['services.anthropic.budget_usd' => 0.01, 'services.anthropic.cout_redaction_usd' => 0.15]);
        EnregistrementConsultation::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id, 'clinique_id' => $this->clinique->id, 'rendez_vous_id' => $this->rendezVous->id,
            'statut' => StatutEnregistrement::Valide, 'redactions' => 1,
        ]);

        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'AUDIO');
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertJsonPath('data.erreur', 'budget');
        $this->assertCount(0, $this->redacteur->appels);
    }

    public function test_le_plafond_atteint_en_cours_de_route_arrete_les_parties_suivantes(): void
    {
        $id = $this->demarrer()->json('data.id');
        // Pendant l'enregistrement, l'organisation atteint son plafond
        $this->organisation->forceFill(['plafond_transcription' => 1])->save();
        $this->enregistrementTranscrit(4000)->forceFill(['statut' => StatutEnregistrement::Valide])->save();

        $this->envoyer($id, 1, 'AUDIO');
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))
            ->assertJsonPath('data.statut', 'erreur')
            ->assertJsonPath('data.erreur', 'plafond');
        $this->assertCount(0, $this->transcripteur->appels);
    }

    public function test_la_purge_efface_l_audio_ancien_et_celui_d_une_erreur_au_bout_de_deux_jours(): void
    {
        $enregistrement = $this->enregistrementTranscrit(60);
        $partie = fn (int $numero, StatutPartie $statut, int $jours) => tap(PartieEnregistrement::query()->create([
            'enregistrement_consultation_id' => $enregistrement->id, 'numero' => $numero, 'statut' => $statut,
            'format' => 'audio/mp4', 'morceaux' => 1, 'fichier' => 'transcriptions/'.$enregistrement->id.'/'.$numero.'.audio',
        ]), function (PartieEnregistrement $p) use ($jours): void {
            Storage::disk('prive')->put($p->fichier, 'AUDIO');
            PartieEnregistrement::query()->whereKey($p->id)->update(['updated_at' => now()->subDays($jours)]);
        });
        $ancienne = $partie(2, StatutPartie::Recue, 8);
        $erreurAncienne = $partie(3, StatutPartie::Erreur, 3);
        $erreurRecente = $partie(4, StatutPartie::Erreur, 1);
        $recente = $partie(5, StatutPartie::Recue, 1);

        $this->artisan('transcriptions:purger')->assertSuccessful();

        Storage::disk('prive')->assertMissing([$ancienne->fichier, $erreurAncienne->fichier]);
        Storage::disk('prive')->assertExists([$erreurRecente->fichier, $recente->fichier]);
        $this->assertNull($erreurAncienne->refresh()->fichier);
        $this->assertSame(StatutPartie::Erreur, $ancienne->refresh()->statut);
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
        $this->assertCount(1, Storage::disk('prive')->allFiles('transcriptions'));

        EnregistrementConsultation::query()->whereKey($id)->update(['redactions' => 2]);
        PartieEnregistrement::query()->update(['segments' => json_encode([['voix' => 0, 'debut' => 0, 'texte' => 'Bonjour']]), 'duree_secondes' => 90]);

        $this->deleteJson("/api/v1/enregistrements/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();

        // Audio et texte effacés…
        $this->assertSame([], Storage::disk('prive')->allFiles('transcriptions'));
        $this->assertNull(PartieEnregistrement::query()->sole()->segments);
        // … mais ce qui a été payé reste compté : les plafonds ne repartent pas à zéro
        $abandonne = EnregistrementConsultation::query()->findOrFail($id);
        $this->assertSame('abandonne', $abandonne->statut->value);
        $this->assertSame(2, $abandonne->redactions);
        $this->assertSame(90, (int) PartieEnregistrement::query()->sole()->duree_secondes);

        // Un nouvel enregistrement repart d'une fiche neuve
        $this->assertNotSame($id, $this->demarrer()->json('data.id'));
    }

    /** Abandonné pendant que le fournisseur transcrit : la durée payée compte, le texte n'est pas écrit, pas de rédaction. */
    public function test_une_transcription_finie_apres_l_abandon_n_ecrit_pas_le_texte(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertOk();
        $this->transcripteur->pendant = fn () => $this->abandonner($id);

        $this->envoyer($id, 1, 'AUDIO');

        $partie = PartieEnregistrement::query()->sole();
        $this->assertNull($partie->segments);
        $this->assertSame(96, (int) $partie->duree_secondes);
        $this->assertSame([], Storage::disk('prive')->allFiles('transcriptions'));
        $this->assertSame('abandonne', EnregistrementConsultation::query()->findOrFail($id)->statut->value);
        $this->assertSame([], $this->redacteur->appels);
    }

    /** Abandonné pendant la rédaction : il reste abandonné, sans brouillon. */
    public function test_une_redaction_finie_apres_l_abandon_ne_remet_pas_le_brouillon(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->envoyer($id, 1, 'AUDIO');
        $this->redacteur->pendant = fn () => $this->abandonner($id);

        $this->postJson("/api/v1/enregistrements/{$id}/terminer", ['parties' => 1], $this->enTetes($this->jeton))->assertOk();

        $abandonne = EnregistrementConsultation::query()->findOrFail($id);
        $this->assertCount(1, $this->redacteur->appels);
        $this->assertSame('abandonne', $abandonne->statut->value);
        $this->assertNull($abandonne->brouillon);
        $this->assertNull($abandonne->redige_le);
    }

    /** 300 Mo par enregistrement, comptés aux morceaux annoncés : l'audio transcrit effacé ne remet pas à zéro. */
    public function test_l_enregistrement_ne_depasse_pas_trois_cents_mo_meme_apres_transcription(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->morceau($id, 1, 0, 200, 'AA')->assertOk();
        // La partie 1 transcrite, son audio effacé : la partie 2 compte quand même avec elle
        Storage::disk('prive')->deleteDirectory(EnregistrementConsultation::query()->findOrFail($id)->dossierAudio());

        $this->morceau($id, 2, 0, 101, 'AA')->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->morceau($id, 2, 0, 100, 'AA')->assertOk();
    }

    /** L'en-tête du premier morceau se compare au format de la partie, pas à celui du dernier envoi. */
    public function test_l_entete_suit_le_format_de_la_partie(): void
    {
        $id = $this->demarrer()->json('data.id');
        $this->morceauBrut($id, 1, 1, 2, 'BB')->assertOk();

        $this->post("/api/v1/enregistrements/{$id}/parties/1", [
            'morceau' => 0, 'morceaux' => 2, 'format' => 'audio/wav',
            'fichier' => UploadedFile::fake()->createWithContent('morceau', 'RIFF'.str_repeat("\0", 4).'WAVEfmt '),
        ], [...$this->enTetes($this->jeton), 'Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('fichier');
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

    /** Ce que fait l'abandon (DELETE), joué pendant un appel au fournisseur */
    private function abandonner(int $id): void
    {
        PartieEnregistrement::withoutGlobalScopes()->where('enregistrement_consultation_id', $id)->update(['segments' => null, 'fichier' => null]);
        EnregistrementConsultation::withoutGlobalScopes()->whereKey($id)->update(['statut' => StatutEnregistrement::Abandonne->value, 'brouillon' => null]);
    }

    private function demarrer(): TestResponse
    {
        return $this->postJson("/api/v1/rendez-vous/{$this->rendezVous->id}/enregistrement", [], $this->enTetes($this->jeton));
    }

    /** Le premier morceau commence par un vrai en-tête de fichier audio (retiré par le transcripteur factice) */
    private function morceau(int $id, int $numero, int $index, int $total, string $octets): TestResponse
    {
        return $this->morceauBrut($id, $numero, $index, $total, ($index === 0 ? TranscripteurFactice::ENTETE : '').$octets);
    }

    private function morceauBrut(int $id, int $numero, int $index, int $total, string $octets): TestResponse
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
