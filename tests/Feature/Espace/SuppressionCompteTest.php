<?php

namespace Tests\Feature\Espace;

use App\Auth\EmetteurJetons;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\DemandeRenouvellementOrdonnance;
use App\Models\Document;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\FactureProprietaire;
use App\Models\MessageClientele;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Societe;
use App\Models\User;
use App\Notifications\CodeEmailNotification;
use App\Notifications\CodeSuppressionCompte;
use App\Paiements\PasserelleAbonnement;
use App\Services\Comptes\SuppressionCompte;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Suppression du compte depuis l'app (App Store, règle 5.1.1(v) ;
 * décisions de Jim, 27.10.2026) : code par e-mail, effacement 30 jours
 * après la demande, la clinique garde ce qu'elle tient, Plus résilié sans
 * remboursement. Une reconnexion pendant les 30 jours annule.
 */
class SuppressionCompteTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private PasserelleAbonnementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $this->stripe);
        Notification::fake();
        Storage::fake('prive');
    }

    /** Demande le code et rend celui reçu par e-mail. */
    private function code(string $jeton, User $user): string
    {
        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))->assertStatus(202);

        $code = null;
        Notification::assertSentTo($user, CodeSuppressionCompte::class, function (CodeSuppressionCompte $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        return (string) $code;
    }

    /** La ligne est toujours là (la clinique la garde), sans lien vers le compte effacé. */
    private function assertDetache(?Model $ligne, string $colonne): void
    {
        $this->assertNotNull($ligne, 'La clinique doit garder la ligne');
        $this->assertNull($ligne->getAttribute($colonne));
    }

    private function demander(string $jeton, User $user): void
    {
        $this->postJson('/api/v1/espace/compte/suppression', ['code' => $this->code($jeton, $user)], $this->enTetes($jeton))->assertOk();
    }

    public function test_la_suppression_se_confirme_par_un_code_de_8_chiffres_envoye_par_e_mail(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();

        $code = $this->code($jeton, $user);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);

        // Sans le code, ou avec un faux : rien ne se passe
        $this->postJson('/api/v1/espace/compte/suppression', [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('code');
        $faux = $code === '11111111' ? '22222222' : '11111111';
        $this->postJson('/api/v1/espace/compte/suppression', ['code' => $faux], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Code invalide ou expiré.']);
        $this->assertNull($user->refresh()->suppression_demandee_le);

        $this->postJson('/api/v1/espace/compte/suppression', ['code' => $code], $this->enTetes($jeton))
            ->assertOk()
            // 30 jours après la demande (26.11, 10 h), la première nuit qui suit
            ->assertJsonPath('data.efface_le', '2026-11-27');
        $this->assertNotNull($user->refresh()->suppression_demandee_le);
    }

    public function test_cinq_essais_faux_effacent_le_code(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $code = $this->code($jeton, $user);
        $faux = $code === '11111111' ? '22222222' : '11111111';

        foreach (range(1, 5) as $essai) {
            $this->postJson('/api/v1/espace/compte/suppression', ['code' => $faux], $this->enTetes($jeton))->assertUnprocessable();
        }

        $this->postJson('/api/v1/espace/compte/suppression', ['code' => $code], $this->enTetes($jeton))->assertUnprocessable();
        $this->assertNull($user->refresh()->suppression_demandee_le);
    }

    public function test_l_e_mail_du_code_dit_qu_il_sert_a_supprimer_le_compte(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->code($jeton, $user);

        Notification::assertSentTo($user, CodeSuppressionCompte::class, function (CodeSuppressionCompte $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $html = (string) $mail->render();

            $this->assertSame('Code pour supprimer votre compte', $mail->subject);
            $this->assertStringContainsString('Supprimer votre compte', $html);
            $this->assertStringContainsString('confirmer la suppression de votre compte', $html);
            $this->assertStringNotContainsString('Me connecter en un clic', $html);

            return true;
        });
    }

    public function test_l_adresse_de_revue_confirme_avec_son_code_fixe_sans_e_mail(): void
    {
        config(['plateforme.revue_apple.email' => 'revue@veterinary.app', 'plateforme.revue_apple.code' => '24681357']);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['email' => 'revue@veterinary.app']);

        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))->assertStatus(202);
        Notification::assertNothingSent();

        $this->postJson('/api/v1/espace/compte/suppression', ['code' => '24681357'], $this->enTetes($jeton))->assertOk();
        $this->assertNotNull($user->refresh()->suppression_demandee_le);
    }

    public function test_la_demande_deconnecte_tous_les_appareils(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $autre = app(EmetteurJetons::class)->emettreSansClinique($user, 'ipad')->plainTextToken;

        $this->demander($jeton, $user);

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/espace/preferences', $this->enTetes($autre))->assertUnauthorized();
    }

    public function test_le_compte_est_efface_30_jours_apres_la_demande(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->demander($jeton, $user);

        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00', 'Europe/Zurich'));
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->assertTrue(User::query()->whereKey($user->id)->exists(), 'Encore dans les 30 jours');

        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:01', 'Europe/Zurich'));
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->assertFalse(User::query()->whereKey($user->id)->exists());
    }

    public function test_l_effacement_passe_chaque_nuit(): void
    {
        $evenements = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'comptes:effacer'));

        $this->assertCount(1, $evenements);
        $this->assertSame('45 3 * * *', $evenements->first()->expression);
        $this->assertSame('Europe/Zurich', $evenements->first()->timezone);
    }

    public function test_se_reconnecter_pendant_les_30_jours_annule_la_suppression_et_la_reponse_le_dit(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['email' => 'paul@exemple.ch']);
        $this->demander($jeton, $user);

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'paul@exemple.ch'])->assertStatus(202);
        $code = null;
        Notification::assertSentTo($user, CodeEmailNotification::class, function ($notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'paul@exemple.ch', 'code' => $code])
            ->assertSuccessful()
            ->assertJsonPath('data.suppression_annulee', true);
        $this->assertNull($user->refresh()->suppression_demandee_le);

        // Une connexion ordinaire ne dit rien
        $this->getJson('/api/v1/auth/moi', $this->enTetes(app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken))
            ->assertOk()->assertJsonPath('data.suppression_annulee', false);
    }

    public function test_la_clinique_garde_fiche_dossiers_factures_et_demandes_detaches_du_compte(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $suivi = Patient::factory()->create(['client_id' => $fiche->id, 'proprietaire_id' => $user->id]);
        $facture = Facture::factory()->create(['client_id' => $fiche->id, 'organisation_id' => $fiche->organisation_id]);
        $demande = DemandeRendezVous::factory()->create(['user_id' => $user->id]);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertFalse(User::query()->whereKey($user->id)->exists());
        $this->assertDetache(Client::withoutGlobalScopes()->find($fiche->id), 'user_id');
        $this->assertDetache(Patient::withoutGlobalScopes()->find($suivi->id), 'proprietaire_id');
        $this->assertTrue(Facture::withoutGlobalScopes()->whereKey($facture->id)->exists());
        $this->assertDetache(DemandeRendezVous::withoutGlobalScopes()->find($demande->id), 'user_id');
    }

    public function test_la_clinique_garde_messages_et_demandes_de_renouvellement_detaches_du_compte(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $suivi = Patient::factory()->create(['client_id' => $fiche->id]);
        // Une clinique de l'organisation de sa fiche : le fil reste attribuable
        $clinique = Clinique::factory()->create(['societe_id' => Societe::factory()->create(['organisation_id' => $fiche->organisation_id])->id]);
        $message = MessageClientele::query()->create(['clinique_id' => $clinique->id, 'user_id' => $user->id, 'auteur' => 'proprietaire', 'texte' => 'Bonjour']);
        $ordonnance = Ordonnance::factory()->create(['patient_id' => $suivi->id, 'organisation_id' => $fiche->organisation_id]);
        $renouvellement = DemandeRenouvellementOrdonnance::query()->create(['ordonnance_id' => $ordonnance->id, 'user_id' => $user->id]);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertFalse(User::query()->whereKey($user->id)->exists());
        $this->assertDetache(MessageClientele::query()->find($message->id), 'user_id');
        // La clinique sait encore avec qui était le fil : sa fiche client
        $this->assertSame($fiche->id, MessageClientele::query()->find($message->id)?->client_id);
        $this->assertDetache(DemandeRenouvellementOrdonnance::query()->find($renouvellement->id), 'user_id');
    }

    public function test_les_animaux_geres_seul_sont_effaces_avec_leurs_photos_et_documents(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        Storage::disk('prive')->put('patients/rex.jpg', 'photo');
        Storage::disk('prive')->put('documents/rex.pdf', 'pdf');
        $seul = Patient::factory()->create(['client_id' => null, 'organisation_id' => null, 'proprietaire_id' => $user->id, 'photo_chemin' => 'patients/rex.jpg']);
        Document::factory()->create(['patient_id' => $seul->id, 'chemin' => 'documents/rex.pdf']);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertFalse(Patient::withoutGlobalScopes()->whereKey($seul->id)->exists());
        Storage::disk('prive')->assertMissing('patients/rex.jpg');
        Storage::disk('prive')->assertMissing('documents/rex.pdf');
    }

    public function test_plus_en_cours_est_resilie_a_la_demande_sans_remboursement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton([
            'formule' => 'plus', 'statut_abonnement' => 'actif', 'periode_fin' => '2027-03-01', 'resilie_le' => null,
        ]);

        $this->demander($jeton, $user);

        $this->assertSame('2027-03-01', $user->refresh()->resilie_le?->toDateString());
        $this->assertSame([], $this->stripe->prelevements, 'Aucun prélèvement ni remboursement');
    }

    public function test_a_l_effacement_la_carte_et_le_client_stripe_sont_supprimes(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $client = $this->stripe->creerClient('Paul', 'paul@exemple.ch', []);
        $carte = $this->stripe->carteConfirmee($client);
        $user->forceFill(['stripe_client_id' => $client, 'stripe_moyen_paiement_id' => $carte])->save();
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        // Le client supprimé emporte ses cartes : aucun détachement à part, qu'une panne entre deux appels bloquerait
        $this->assertSame([], $this->stripe->detachees);
        $this->assertArrayNotHasKey($carte, $this->stripe->cartes);
        $this->assertContains($client, $this->stripe->clientsSupprimes);
        $this->assertFalse(User::query()->whereKey($user->id)->exists());
    }

    /** Stripe en panne : le compte attend la nuit suivante, rien n'est effacé à moitié. */
    public function test_stripe_en_panne_le_compte_attend_la_nuit_suivante(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $client = $this->stripe->creerClient('Paul', 'paul@exemple.ch', []);
        $user->forceFill(['stripe_client_id' => $client])->save();
        $this->demander($jeton, $user);
        $this->stripe->panne = 'Stripe indisponible';

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->assertTrue(User::query()->whereKey($user->id)->exists());

        $this->stripe->panne = null;
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->assertFalse(User::query()->whereKey($user->id)->exists());
    }

    public function test_un_compte_de_l_equipe_d_une_clinique_ne_peut_pas_se_supprimer(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        Employe::factory()->create(['user_id' => $user->id]);
        $message = 'Votre compte fait partie de l’équipe d’une clinique. Quittez d’abord l’équipe, ou demandez à son administrateur de vous en retirer, puis supprimez votre compte.';

        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['compte' => $message]);
        $this->postJson('/api/v1/espace/compte/suppression', ['code' => '12345678'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['compte' => $message]);
        Notification::assertNothingSent();
        $this->assertNull($user->refresh()->suppression_demandee_le);
    }

    /** Pièces comptables de Meteora (CO 958f, 10 ans) : les factures Plus restent, détachées, avec le nom et l'adresse du titulaire. */
    public function test_les_factures_plus_restent_detachees_avec_le_titulaire(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Paul Rochat', 'email' => 'paul@exemple.ch']);
        $facture = FactureProprietaire::query()->forceCreate(['user_id' => $user->id, 'numero' => 'P-2026-0001', 'emise_le' => '2026-10-01', 'libelle' => 'Veterinary Plus', 'montant' => 12, 'statut' => 'payee']);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $gardee = FactureProprietaire::query()->find($facture->id);
        $this->assertDetache($gardee, 'user_id');
        $this->assertSame(['Paul Rochat', 'paul@exemple.ch'], [$gardee->titulaire_nom, $gardee->titulaire_email]);
    }

    /** La limite d'envoi du code suit le compte : un champ e-mail glissé dans la requête ne la contourne pas. */
    public function test_l_envoi_du_code_est_limite_par_compte(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        foreach (range(1, 3) as $envoi) {
            $this->postJson('/api/v1/espace/compte/suppression/code', ['email' => "leurre{$envoi}@exemple.ch"], $this->enTetes($jeton))->assertStatus(202);
        }

        $this->postJson('/api/v1/espace/compte/suppression/code', ['email' => 'leurre4@exemple.ch'], $this->enTetes($jeton))
            ->assertStatus(429)->assertJsonPath('message', 'Trop de codes demandés : réessayez dans un quart d’heure.');
    }

    /** Une session encore en vol pendant la demande ne se prolonge pas, et n'annule rien. */
    public function test_un_rafraichissement_pendant_la_suppression_est_refuse(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $user->forceFill(['suppression_demandee_le' => now()])->save();

        $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))->assertUnauthorized();
        $this->assertNotNull($user->refresh()->suppression_demandee_le);
    }

    /** Reconnecté juste avant le passage de la nuit : l'effacement relit la demande et ne fait rien. */
    public function test_l_effacement_relit_la_demande(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->demander($jeton, $user);
        $this->travel(31)->days();
        $vieux = User::query()->find($user->id);
        app(EmetteurJetons::class)->emettreSansClinique($user);

        app(SuppressionCompte::class)->effacer($vieux);

        $this->assertTrue(User::query()->whereKey($user->id)->exists());
    }

    /** L'adresse de revue : la réponse dit qu'aucun e-mail n'est parti (l'écran ne prétend pas le contraire). */
    public function test_l_adresse_de_revue_apprend_qu_aucun_e_mail_n_est_parti(): void
    {
        config(['plateforme.revue_apple.email' => 'revue@veterinary.app', 'plateforme.revue_apple.code' => '24681357']);
        ['jeton' => $jeton] = $this->proprietaireAvecJeton(['email' => 'revue@veterinary.app']);
        ['jeton' => $autre] = $this->proprietaireAvecJeton();

        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))->assertStatus(202)->assertJsonPath('data.envoye', false);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($autre))->assertStatus(202)->assertJsonPath('data.envoye', true);
    }
}
