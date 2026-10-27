<?php

namespace Tests\Feature\Espace;

use App\Auth\EmetteurJetons;
use App\Enums\DecisionVerification;
use App\Enums\Specialite;
use App\Enums\StatutIdentite;
use App\Enums\StatutVerificationVeterinaire;
use App\Enums\TypeParcours;
use App\Http\Resources\V1\CompteRenduResource;
use App\Http\Resources\V1\JetonEmisResource;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\DemandeRendezVous;
use App\Models\DemandeRenouvellementOrdonnance;
use App\Models\Document;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\FactureProprietaire;
use App\Models\MessageClientele;
use App\Models\Ordonnance;
use App\Models\Passkey;
use App\Models\Patient;
use App\Models\Societe;
use App\Models\User;
use App\Notifications\CodeEmailNotification;
use App\Notifications\CodeSuppressionCompte;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserelleIdentite;
use App\Services\Abonnements\AbonnementSaas;
use App\Services\Comptes\SuppressionCompte;
use Carbon\CarbonImmutable;
use Database\Factories\PasskeyFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\Doubles\PasserelleIdentiteFactice;
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
        // Stripe Identity : un double (la vraie passerelle, sans clé en test, serait refusée)
        $this->app->instance(PasserelleIdentite::class, new PasserelleIdentiteFactice);
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

    /** Un ancien membre d'équipe : la clinique a mis fin à son accès. */
    private function ancienMembre(array $attributs = []): array
    {
        $compte = $this->proprietaireAvecJeton(['name' => 'Rachel Solal', 'email' => 'rachel@exemple.ch', 'gln' => '7601000000001', ...$attributs]);
        $fiche = Employe::factory()->create(['user_id' => $compte['user']->id, 'nom' => 'Rachel Solal', 'acces_suspendu' => true]);

        return [...$compte, 'fiche' => $fiche];
    }

    public function test_un_ancien_membre_d_equipe_peut_supprimer_son_compte(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->ancienMembre();

        $this->demander($jeton, $user);

        $this->assertNotNull($user->refresh()->suppression_demandee_le);
    }

    /**
     * Décision de Jim (27.10.2026) : le compte devient une trace sans
     * connexion qui ne garde que le prénom ; la fiche d'équipe et chaque
     * pièce signée restent liées à cette trace.
     */
    public function test_l_ancien_membre_devient_une_trace_sans_connexion_qui_ne_garde_que_le_prenom(): void
    {
        ['user' => $user, 'jeton' => $jeton, 'fiche' => $fiche] = $this->ancienMembre();
        $user->forceFill([
            'password' => Hash::make('mot-de-passe-de-test'), 'medreg_id' => 1234, 'gln_verifie_le' => now(), 'verification_veterinaire_le' => now(),
            'identite_nom' => 'SOLAL', 'identite_raison' => 'à la main', 'identite_verifiee_le' => now(), 'identite_payee_le' => now(),
            'est_moderateur' => true, 'presentation' => 'Vétérinaire depuis 2010', 'langues' => ['fr', 'de'], 'annuaire_accord_le' => now(),
            'code_employe' => 'K7F3QZ8M', 'apple_compte' => '11111111-2222-3333-4444-555555555555', 'apple_abonnement_id' => 'abo-1',
            'apple_fin' => now()->addYear(), 'apple_renouvelle' => true, 'apple_signe_le' => now(), 'moyen_paiement' => 'Visa •••• 4242',
            'carte_expire_le' => '2028-08-31', 'statut_abonnement' => 'resilie', 'periode_fin' => '2026-12-01', 'periode_debut' => '2025-12-01',
            'ancre_jour' => 1, 'prochaine_tentative_le' => now(), 'resilie_le' => '2026-12-01',
            'verification_veterinaire' => StatutVerificationVeterinaire::cases()[0]->value, 'identite_statut' => StatutIdentite::cases()[0]->value,
            'identite_session_id' => 'vs_rachel', 'stripe_client_id' => 'cus_rachel', 'stripe_moyen_paiement_id' => 'pm_rachel', 'carte_alertee_le' => '2026-10-01',
        ])->save();
        $ficheClient = Client::factory()->create();
        $suivi = Patient::factory()->create(['client_id' => $ficheClient->id]);
        DB::table('demandes_rattachement')->insert(['user_id' => $user->id, 'organisation_id' => $ficheClient->organisation_id, 'patient_id' => $suivi->id, 'puce_saisie' => '756098605321680']);
        $demande = DemandeRendezVous::factory()->create(['user_id' => $user->id]);
        $message = MessageClientele::query()->create(['clinique_id' => Clinique::factory()->create()->id, 'user_id' => $user->id, 'auteur' => 'proprietaire', 'texte' => 'Bonjour']);
        $renouvellement = DemandeRenouvellementOrdonnance::query()->create(['ordonnance_id' => Ordonnance::factory()->create(['patient_id' => $suivi->id, 'organisation_id' => $ficheClient->organisation_id])->id, 'user_id' => $user->id]);
        $facturePlus = FactureProprietaire::query()->forceCreate(['user_id' => $user->id, 'numero' => 'P-2026-0009', 'emise_le' => '2026-10-01', 'libelle' => 'Veterinary Plus', 'montant' => 12, 'statut' => 'payee']);
        DB::table('revocations_app_store')->insert(['user_id' => $user->id, 'transaction_id' => 'tx-rachel', 'produit' => 'plus', 'revoquee_le' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('specialites_veterinaire')->insert(['user_id' => $user->id, 'specialite' => Specialite::cases()[0]->value]);
        DB::table('parcours_veterinaire')->insert(['user_id' => $user->id, 'type' => TypeParcours::cases()[0]->value, 'titre' => 'Diplôme']);
        DB::table('preferences_proprietaire')->insert(['user_id' => $user->id]);
        DB::table('achats_planete')->insert(['user_id' => $user->id, 'article' => 'planete-test']);
        DB::table('decisions_verification')->insert(['user_id' => $user->id, 'decision' => DecisionVerification::cases()[0]->value]);
        $compteRendu = CompteRendu::factory()->create(['user_id' => $user->id]);
        $facture = Facture::factory()->create(['cree_par_id' => $user->id]);
        PasskeyFactory::new()->create(['user_id' => $user->id]);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $trace = User::query()->find($user->id);
        $this->assertNotNull($trace, 'La trace reste');
        $this->assertSame('Rachel', $trace->name);
        $this->assertNotSame('rachel@exemple.ch', $trace->email);
        $this->assertNull($trace->gln);
        $this->assertNull($trace->email_verified_at);
        $this->assertNull($trace->suppression_demandee_le);
        $this->assertSame(0, $trace->tokens()->count());
        $this->assertFalse(Passkey::query()->where('user_id', $user->id)->exists());
        // Rien de personnel ne reste que le prénom : chaque colonne, chaque table
        foreach (['medreg_id', 'gln_verifie_le', 'verification_veterinaire', 'verification_veterinaire_le', 'identite_statut', 'identite_session_id',
            'identite_nom', 'identite_raison', 'identite_verifiee_le', 'identite_payee_le', 'presentation', 'langues', 'annuaire_accord_le',
            'code_employe', 'apple_compte', 'apple_abonnement_id', 'apple_fin', 'apple_renouvelle', 'apple_signe_le', 'stripe_client_id',
            'stripe_moyen_paiement_id', 'moyen_paiement', 'carte_expire_le', 'carte_alertee_le', 'statut_abonnement', 'periode_fin',
            'periode_debut', 'ancre_jour', 'prochaine_tentative_le', 'resilie_le'] as $colonne) {
            $this->assertNull($trace->getRawOriginal($colonne), $colonne);
        }
        $this->assertFalse((bool) $trace->est_moderateur);
        $this->assertFalse(Hash::check('mot-de-passe-de-test', $trace->password));
        foreach (['specialites_veterinaire', 'parcours_veterinaire', 'preferences_proprietaire', 'achats_planete', 'decisions_verification', 'demandes_rattachement'] as $table) {
            $this->assertFalse(DB::table($table)->where('user_id', $user->id)->exists(), $table);
        }
        // Ce que la clinique ou Meteora garde, détaché de la trace
        $this->assertDetache(DemandeRendezVous::withoutGlobalScopes()->find($demande->id), 'user_id');
        $this->assertDetache(MessageClientele::query()->find($message->id), 'user_id');
        $this->assertDetache(DemandeRenouvellementOrdonnance::query()->find($renouvellement->id), 'user_id');
        $this->assertDetache(FactureProprietaire::query()->find($facturePlus->id), 'user_id');
        $this->assertFalse(DB::table('revocations_app_store')->where('user_id', $user->id)->exists());
        // La fiche d'équipe et les pièces signées gardent leur auteur
        $this->assertSame($user->id, Employe::withoutGlobalScopes()->find($fiche->id)?->user_id);
        $this->assertSame($user->id, CompteRendu::withoutGlobalScopes()->find($compteRendu->id)?->user_id);
        $this->assertSame($user->id, Facture::withoutGlobalScopes()->find($facture->id)?->cree_par_id);
        // La nuit suivante ne la reprend pas
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->assertTrue(User::query()->whereKey($user->id)->exists());
    }

    /** La trace ne se connecte plus : son ancienne adresse ouvre un compte neuf, vide. */
    public function test_la_trace_ne_se_connecte_plus(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->ancienMembre();
        $this->demander($jeton, $user);
        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'rachel@exemple.ch'])->assertStatus(202);
        $code = null;
        Notification::assertSentOnDemand(CodeEmailNotification::class, function ($notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'rachel@exemple.ch', 'code' => $code])->assertSuccessful();

        $neuf = User::query()->where('email', 'rachel@exemple.ch')->first();
        $this->assertNotNull($neuf);
        $this->assertNotSame($user->id, $neuf->id);
    }

    /** Côté propriétaire, comme un compte effacé : fiche client détachée, animaux gérés seul effacés. */
    public function test_la_trace_n_est_plus_liee_a_ses_fiches_de_proprietaire(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->ancienMembre();
        $carte = Client::factory()->create(['user_id' => $user->id]);
        $seul = Patient::factory()->create(['client_id' => null, 'organisation_id' => null, 'proprietaire_id' => $user->id]);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertDetache(Client::withoutGlobalScopes()->find($carte->id), 'user_id');
        $this->assertFalse(Patient::withoutGlobalScopes()->whereKey($seul->id)->exists());
    }

    /** Réembauché entre-temps (accès rendu) : la demande tombe, rien n'est touché. */
    public function test_un_membre_redevenu_actif_n_est_pas_efface(): void
    {
        ['user' => $user, 'jeton' => $jeton, 'fiche' => $fiche] = $this->ancienMembre();
        $this->demander($jeton, $user);
        $fiche->forceFill(['acces_suspendu' => false])->save();

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertSame('Rachel Solal', $user->refresh()->name);
        $this->assertNull($user->suppression_demandee_le);
    }

    /** Connexion d'un ancien membre : aucune clinique fermée n'est proposée, il arrive dans son espace et peut supprimer son compte. */
    public function test_un_ancien_membre_se_connecte_dans_son_espace_et_y_trouve_la_suppression(): void
    {
        ['user' => $user] = $this->ancienMembre();
        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'rachel@exemple.ch'])->assertStatus(202);
        $code = null;
        Notification::assertSentTo($user, CodeEmailNotification::class, function ($notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });
        $this->app['auth']->forgetGuards();

        $jeton = $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'rachel@exemple.ch', 'code' => $code])
            ->assertSuccessful()
            ->assertJsonPath('data.clinique', null)
            ->assertJsonPath('data.cliniques', [])
            ->json('data.token');

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();
        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))->assertStatus(202);
    }

    /** Contrat terminé (date passée), même avant que la tâche de nuit pose la suspension : accès fermé, suppression permise. */
    public function test_un_contrat_termine_compte_comme_un_acces_ferme(): void
    {
        ['user' => $user, 'jeton' => $jeton, 'fiche' => $fiche] = $this->ancienMembre();
        $fiche->forceFill(['acces_suspendu' => false, 'fin_contrat_le' => now()->subDays(2)->toDateString()])->save();

        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))->assertStatus(202);
    }

    /** Suspendu dans une clinique mais actif dans une autre : refusé. */
    public function test_actif_dans_une_autre_clinique_reste_refuse(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->ancienMembre();
        Employe::factory()->create(['user_id' => $user->id, 'acces_suspendu' => false]);

        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('compte');
    }

    /** La vérification d'identité Stripe est effacée chez Stripe (redact), pour une trace comme pour un compte effacé. */
    public function test_la_verification_d_identite_est_effacee_chez_stripe(): void
    {
        $identite = new PasserelleIdentiteFactice;
        $this->app->instance(PasserelleIdentite::class, $identite);
        ['user' => $vet, 'jeton' => $jetonVet] = $this->ancienMembre(['identite_session_id' => 'vs_rachel']);
        ['user' => $proprio, 'jeton' => $jetonProprio] = $this->proprietaireAvecJeton(['identite_session_id' => 'vs_paul']);
        $this->demander($jetonVet, $vet);
        $this->app['auth']->forgetGuards();
        $this->demander($jetonProprio, $proprio);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['vs_rachel', 'vs_paul'], $identite->effacees);
    }

    /** Stripe Identity en panne passagère : rien n'est effacé, le compte attend la nuit suivante. */
    public function test_stripe_identity_en_panne_le_compte_attend(): void
    {
        $identite = new PasserelleIdentiteFactice;
        $this->app->instance(PasserelleIdentite::class, $identite);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['identite_session_id' => 'vs_paul']);
        $this->demander($jeton, $user);
        $identite->pannePassagere = true;

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->assertTrue(User::query()->whereKey($user->id)->exists());

        $identite->pannePassagere = false;
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->assertFalse(User::query()->whereKey($user->id)->exists());
    }

    /** Clé Stripe refusée (renouvelée sans mise à jour) : rien n'est effacé, la pièce n'est pas abandonnée chez Stripe. */
    public function test_cle_stripe_refusee_le_compte_attend(): void
    {
        $identite = new PasserelleIdentiteFactice;
        $this->app->instance(PasserelleIdentite::class, $identite);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['identite_session_id' => 'vs_paul']);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        foreach ([401, 403, 0] as $statut) {
            $identite->refusEffacement = $statut;
            $this->artisan('comptes:effacer')->assertSuccessful();
            $this->assertTrue(User::query()->whereKey($user->id)->exists(), "statut {$statut}");
        }
    }

    /** Session déjà partie chez Stripe : signalé, le compte est effacé quand même. */
    public function test_session_introuvable_chez_stripe_le_compte_est_efface(): void
    {
        $identite = new PasserelleIdentiteFactice;
        $this->app->instance(PasserelleIdentite::class, $identite);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['identite_session_id' => 'vs_parti']);
        $this->demander($jeton, $user);
        $identite->refusEffacement = 404;

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertFalse(User::query()->whereKey($user->id)->exists());
    }

    /* ——— Défauts faibles restants (demande de Jim, 28.10.2026) ——— */

    /** Clinique à New York : la fin de contrat se compare à sa date à elle, comme à sa porte. */
    public function test_la_fin_de_contrat_se_compare_a_la_date_de_la_clinique(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 02:00', 'Europe/Zurich'));
        $newYork = Clinique::factory()->create(['fuseau_horaire' => 'America/New_York']);
        ['employe' => $fiche, 'user' => $user] = $this->employeAvecJeton($newYork);
        // Dernier jour du contrat à New York (le 27 y est encore aujourd'hui, à Zurich c'est le 28)
        $fiche->forceFill(['fin_contrat_le' => '2026-10-27'])->save();
        $jeton = app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken;

        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('compte');
    }

    /** Le dernier jour du contrat, le membre est encore actif. */
    public function test_le_dernier_jour_du_contrat_le_membre_est_encore_actif(): void
    {
        ['user' => $user, 'jeton' => $jeton, 'fiche' => $fiche] = $this->ancienMembre();
        $fiche->forceFill(['acces_suspendu' => false, 'fin_contrat_le' => aujourdhui()->toDateString()])->save();

        $this->postJson('/api/v1/espace/compte/suppression/code', [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('compte');
    }

    /** Un contrat terminé (date passée, nuit pas encore passée) n'est plus proposé à la connexion. */
    public function test_un_contrat_termine_n_est_plus_propose_a_la_connexion(): void
    {
        ['user' => $user, 'fiche' => $fiche] = $this->ancienMembre();
        $fiche->forceFill(['acces_suspendu' => false, 'fin_contrat_le' => aujourdhui()->subDay()->toDateString()])->save();

        $this->assertCount(0, app(EmetteurJetons::class)->rattachements($user->refresh()));
    }

    /** Rétabli après une suspension, connecté pendant celle-ci (session sans clinique) : il rouvre sa clinique depuis sa session. */
    public function test_un_membre_retabli_rouvre_sa_clinique_depuis_sa_session_sans_clinique(): void
    {
        $clinique = Clinique::factory()->create();
        ['employe' => $fiche, 'user' => $user] = $this->employeAvecJeton($clinique);
        $fiche->forceFill(['acces_suspendu' => true])->save();
        $sansClinique = app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken;
        $fiche->forceFill(['acces_suspendu' => false])->save();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($sansClinique))->assertOk()->assertJsonPath('data.cliniques.0.id', $clinique->id);
        $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $clinique->id], $this->enTetes($sansClinique))
            ->assertOk()->assertJsonPath('data.clinique.id', $clinique->id);
    }

    /** Signature d'un compte rendu : le nom de la fiche d'équipe (la trace ne garde que le prénom). */
    public function test_la_signature_d_un_compte_rendu_affiche_le_nom_de_la_fiche(): void
    {
        $user = User::factory()->create(['name' => 'Rachel']);
        $fiche = Employe::factory()->create(['user_id' => $user->id, 'nom' => 'Rachel Solal']);
        $compteRendu = CompteRendu::factory()->create(['signe_par_id' => $fiche->id, 'signe_le' => now()]);

        $donnees = (new CompteRenduResource($compteRendu->refresh()))->toArray(request());

        $this->assertSame('Rachel Solal', $donnees['signe_par']);
    }

    /** Le prénom gardé saute un titre. */
    public function test_le_prenom_de_la_trace_saute_un_titre(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->ancienMembre(['name' => 'Dre Rachel Solal']);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertSame('Rachel', $user->refresh()->name);
        $this->assertNotNull($user->efface_le);
    }

    /** Une fiche liée à une trace ne se rétablit pas (elle compterait dans la limite d'employés). */
    public function test_une_fiche_liee_a_une_trace_ne_se_retablit_pas(): void
    {
        $clinique = Clinique::factory()->create();
        ['employe' => $fiche, 'user' => $user] = $this->employeAvecJeton($clinique);
        $fiche->forceFill(['acces_suspendu' => true])->save();
        $this->demander(app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken, $user);
        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->app['auth']->forgetGuards();
        // Un administrateur de la clinique, connecté maintenant
        ['jeton' => $admin] = $this->administrateurAvecJeton($clinique);

        $this->postJson("/api/v1/equipe/employes/{$fiche->id}/retablir", [], $this->enTetes($admin))
            ->assertUnprocessable()->assertJsonValidationErrors(['acces' => 'Ce compte a été supprimé : son accès ne peut pas être rétabli.']);
        $this->assertTrue((bool) $fiche->refresh()->acces_suspendu);
    }

    /** Reconnecté pendant l'effacement (après la suppression du client Stripe) : le compte reste, sans lien vers ce client supprimé. */
    public function test_une_connexion_pendant_l_effacement_ne_laisse_pas_de_client_stripe_supprime(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $client = $this->stripe->creerClient('Paul', 'paul@exemple.ch', []);
        $user->forceFill(['stripe_client_id' => $client, 'stripe_moyen_paiement_id' => $this->stripe->carteConfirmee($client), 'moyen_paiement' => 'Visa •••• 4242'])->save();
        $this->demander($jeton, $user);
        // La connexion arrive juste après la suppression du client chez Stripe
        $this->stripe->pendantSuppressionClient = fn () => User::query()->whereKey($user->id)->update(['suppression_demandee_le' => null]);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $user->refresh();
        $this->assertSame([null, null, null], [$user->stripe_client_id, $user->stripe_moyen_paiement_id, $user->moyen_paiement]);
    }

    /** Une facture Plus en attente chez Stripe : annulée à l'effacement, plus rien ne la reprend. */
    public function test_une_facture_plus_en_attente_ne_bloque_rien_apres_l_effacement(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $client = $this->stripe->creerClient('Paul', 'paul@exemple.ch', []);
        $carte = $this->stripe->carteConfirmee($client);
        $user->forceFill(['stripe_client_id' => $client, 'stripe_moyen_paiement_id' => $carte])->save();
        $intention = $this->stripe->prelever($client, $carte, 1200, 'Plus', [], false, 'cle-essai');
        $facture = FactureProprietaire::query()->forceCreate([
            'user_id' => $user->id, 'numero' => 'P-2026-0010', 'emise_le' => '2026-10-01', 'libelle' => 'Veterinary Plus', 'montant' => 12,
            'statut' => 'a_payer', 'stripe_paiement_id' => $intention['id'],
            'metadonnees' => ['demande_en_suspens' => ['le' => now()->subHour()->toIso8601String()]],
        ]);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();
        app(AbonnementSaas::class)->surPrelevement(['id' => $intention['id'], 'status' => 'succeeded', 'metadata' => ['facture' => 'proprietaire:'.$facture->id]]);

        $this->assertFalse(User::query()->whereKey($user->id)->exists());
        $this->assertSame('annulee', $facture->refresh()->statut);
    }

    /** Factures Plus détachées : effacées après 10 ans (fin de l'année d'émission + 10), chaque mois. */
    public function test_les_factures_plus_detachees_sont_effacees_apres_10_ans(): void
    {
        $this->travelTo(CarbonImmutable::parse('2037-01-05 10:00', 'Europe/Zurich'));
        $vieille = FactureProprietaire::query()->forceCreate(['user_id' => null, 'numero' => 'P-2026-0001', 'emise_le' => '2026-12-31', 'libelle' => 'Plus', 'montant' => 12, 'statut' => 'payee']);
        $recente = FactureProprietaire::query()->forceCreate(['user_id' => null, 'numero' => 'P-2027-0001', 'emise_le' => '2027-01-02', 'libelle' => 'Plus', 'montant' => 12, 'statut' => 'payee']);
        $titulaire = User::factory()->create();
        $gardee = FactureProprietaire::query()->forceCreate(['user_id' => $titulaire->id, 'numero' => 'P-2025-0001', 'emise_le' => '2025-06-01', 'libelle' => 'Plus', 'montant' => 12, 'statut' => 'payee']);

        $this->artisan('factures:purger-plus')->assertSuccessful();

        $this->assertFalse(FactureProprietaire::query()->whereKey($vieille->id)->exists());
        $this->assertTrue(FactureProprietaire::query()->whereKey($recente->id)->exists());
        $this->assertTrue(FactureProprietaire::query()->whereKey($gardee->id)->exists(), 'Celle d’un compte encore là reste');
        $this->assertCount(1, collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'factures:purger-plus')));
    }

    /** Un message porte sa fiche client dès l'envoi. */
    public function test_un_message_porte_sa_fiche_client_des_l_envoi(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $clinique = Clinique::factory()->create(['societe_id' => Societe::factory()->create(['organisation_id' => $fiche->organisation_id])->id]);

        $id = $this->postJson('/api/v1/espace/messages', ['clinique_id' => $clinique->id, 'texte' => 'Bonjour'], $this->enTetes($jeton))->assertCreated()->json('data.id');

        $this->assertSame($fiche->id, MessageClientele::query()->find($id)?->client_id);
    }

    /** La reconnexion qui annule dit aussi que Plus reste résilié, s'il l'est. */
    public function test_la_reconnexion_dit_que_plus_reste_resilie(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['email' => 'paul@exemple.ch', 'formule' => 'plus', 'statut_abonnement' => 'actif', 'periode_fin' => '2027-03-01']);
        $this->demander($jeton, $user);

        $session = (new JetonEmisResource(app(EmetteurJetons::class)->emettreSansClinique($user->refresh()), null))->toArray(request());

        $this->assertTrue($session['suppression_annulee']);
        $this->assertTrue($session['plus_resilie']);
    }

    /** Une session d'identité introuvable chez Stripe est signalée. */
    public function test_une_session_introuvable_est_signalee(): void
    {
        Exceptions::fake();
        $identite = new PasserelleIdentiteFactice;
        $identite->refusEffacement = 404;
        $this->app->instance(PasserelleIdentite::class, $identite);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['identite_session_id' => 'vs_parti']);
        $this->demander($jeton, $user);

        $this->travel(31)->days();
        $this->artisan('comptes:effacer')->assertSuccessful();

        Exceptions::assertReported(ErreurPaiement::class);
    }

    /** Plus de 100 comptes la même nuit : aucun n'est sauté. */
    public function test_l_effacement_de_nuit_traite_plus_de_100_comptes(): void
    {
        User::factory()->count(150)->create(['suppression_demandee_le' => now()->subDays(31)]);

        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertSame(0, User::query()->whereNotNull('suppression_demandee_le')->count());
    }

    /** Le retour arrière des migrations ne perd aucune ligne gardée (messages, renouvellements, factures détachés). */
    public function test_le_retour_arriere_des_migrations_ne_perd_aucune_ligne(): void
    {
        $fiche = Client::factory()->create();
        $message = MessageClientele::query()->create(['clinique_id' => Clinique::factory()->create()->id, 'user_id' => null, 'auteur' => 'proprietaire', 'texte' => 'Gardé']);
        $facture = FactureProprietaire::query()->forceCreate(['user_id' => null, 'numero' => 'P-2026-0020', 'emise_le' => '2026-10-01', 'libelle' => 'Plus', 'montant' => 12, 'statut' => 'payee']);
        $migrations = collect(['2026_10_27_000003_traces_des_comptes', '2026_10_27_000002_suppression_des_comptes_pieces_et_fils', '2026_10_27_000001_suppression_des_comptes'])
            ->map(fn (string $nom) => require database_path("migrations/{$nom}.php"));

        $migrations->each(fn ($migration) => $migration->down());
        $this->assertTrue(MessageClientele::query()->whereKey($message->id)->exists());
        $this->assertTrue(FactureProprietaire::query()->whereKey($facture->id)->exists());
        $migrations->reverse()->each(fn ($migration) => $migration->up());
        $this->assertTrue(Client::query()->withoutGlobalScopes()->whereKey($fiche->id)->exists());
    }
}
