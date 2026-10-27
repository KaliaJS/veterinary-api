<?php

namespace Tests\Feature\Auth;

use App\Mail\AccesSurInvitation;
use App\Models\Client;
use App\Models\InvitationAcces;
use App\Models\InvitationEmploye;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Accès sur invitation (décision de Jim, 27.10.2026) : tant que
 * `ACCES_SUR_INVITATION` est vrai, seules les adresses invitées créent un
 * compte (liste de la plateforme, équipe ou fiche client d'une clinique
 * admise, c'est-à-dire vérifiée). Les autres reçoivent un e-mail « sur
 * invitation » au lieu d'un code ; l'écran leur répond comme à tout le monde.
 */
class AccesSurInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plateforme.acces_sur_invitation' => true]);
        Mail::fake();
        Notification::fake();
    }

    private function demanderCode(string $email): TestResponse
    {
        return $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $email]);
    }

    /** Le code envoyé à une adresse sans compte (null : aucun code parti). */
    private function codeEnvoye(string $email): ?string
    {
        $code = null;
        foreach (Notification::sentNotifications() as $parCle) {
            foreach ($parCle as $parNotification) {
                foreach ($parNotification[CodeEmailNotification::class] ?? [] as $envoi) {
                    if (($envoi['notifiable']->routes['mail'] ?? null) === $email) {
                        $code = $envoi['notification']->code;
                    }
                }
            }
        }

        return $code;
    }

    private function verifier(string $email, string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $email, 'code' => $code]);
    }

    private function inviterDansEquipe(Organisation $organisation, string $email, string $expire = '+14 days'): void
    {
        (new InvitationEmploye(['organisation_id' => $organisation->id, 'email' => $email, 'nom' => 'Léa Fontaine', 'donnees' => [], 'expire_le' => now()->modify($expire)]))
            ->forceFill(['jeton_empreinte' => hash('sha256', 'lien-'.$email)])
            ->save();
    }

    private function cliniqueAdmise(): Organisation
    {
        return Organisation::factory()->create(['verification' => 'verifiee']);
    }

    public function test_une_adresse_non_invitee_ne_cree_pas_de_compte(): void
    {
        $this->demanderCode('paul@exemple.ch')->assertStatus(202);

        $this->assertNull($this->codeEnvoye('paul@exemple.ch'));
        $this->verifier('paul@exemple.ch', '12345678')->assertUnprocessable();
        $this->assertSame(0, User::query()->where('email', 'paul@exemple.ch')->count());
    }

    /** Un code demandé avant la fermeture ne crée pas de compte après. */
    public function test_un_code_obtenu_avant_la_fermeture_ne_cree_pas_de_compte(): void
    {
        config(['plateforme.acces_sur_invitation' => false]);
        $this->demanderCode('paul@exemple.ch')->assertStatus(202);
        $code = $this->codeEnvoye('paul@exemple.ch');
        config(['plateforme.acces_sur_invitation' => true]);

        $this->verifier('paul@exemple.ch', (string) $code)->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Code invalide ou expiré.']);
        $this->assertSame(0, User::query()->where('email', 'paul@exemple.ch')->count());
    }

    public function test_une_adresse_non_invitee_recoit_l_email_sur_invitation(): void
    {
        $this->demanderCode('Paul@Exemple.ch')->assertStatus(202);

        Mail::assertSent(AccesSurInvitation::class, fn (AccesSurInvitation $mail): bool => $mail->hasTo('paul@exemple.ch'));
        Notification::assertNothingSent();
        config(['plateforme.emetteur.email' => 'bonjour@veterinary.app']);
        $html = (new AccesSurInvitation)->render();
        $this->assertStringContainsString('Veterinary s’ouvre sur invitation seulement', str_replace("'", '’', $html));
        $this->assertStringContainsString('bonjour@veterinary.app', $html);
    }

    public function test_l_ecran_repond_a_une_adresse_non_invitee_comme_aux_autres(): void
    {
        InvitationAcces::query()->create(['email' => 'marie@lac.ch']);

        $invitee = $this->demanderCode('marie@lac.ch')->assertStatus(202)->json();
        $nonInvitee = $this->demanderCode('paul@exemple.ch')->assertStatus(202)->json();

        $this->assertSame($invitee, $nonInvitee);
    }

    /**
     * Rien ne trahit qui est invité (vérification du 27.10.2026) : cinq essais
     * faux, une demande de code, un sixième essai. Invitée, avec un compte ou
     * inconnue, la réponse est la même ; et le serveur fait le même travail
     * (un code haché rangé), sans écart de temps.
     */
    public function test_ni_le_compteur_d_essais_ni_le_travail_ne_trahissent_qui_est_invite(): void
    {
        InvitationAcces::query()->create(['email' => 'marie@lac.ch']);
        User::factory()->create(['email' => 'paul@exemple.ch']);
        $reponses = [];
        $couts = [];

        foreach (['marie@lac.ch', 'paul@exemple.ch', 'inconnu@exemple.ch'] as $email) {
            foreach (range(1, 5) as $essai) {
                $this->verifier($email, '00000000');
            }
            $this->demanderCode($email)->assertStatus(202);
            $this->assertTrue(Cache::has('auth:code-email:'.hash('sha256', $email)), $email);
            // Le même hachage, au même coût : le même temps de calcul
            $couts[$email] = password_get_info((string) Cache::get('auth:code-email:'.hash('sha256', $email)))['options'];
            $reponses[$email] = $this->verifier($email, '00000000')->json('errors.code');
        }

        $this->assertSame(['Code invalide ou expiré.'], $reponses['marie@lac.ch']);
        $this->assertSame($reponses['marie@lac.ch'], $reponses['paul@exemple.ch']);
        $this->assertSame($reponses['marie@lac.ch'], $reponses['inconnu@exemple.ch']);
        $this->assertSame($couts['marie@lac.ch'], $couts['paul@exemple.ch']);
        $this->assertSame($couts['marie@lac.ch'], $couts['inconnu@exemple.ch']);
    }

    public function test_une_adresse_de_la_liste_cree_son_compte(): void
    {
        $invitation = InvitationAcces::query()->create(['email' => 'marie@lac.ch']);

        $this->demanderCode('Marie@Lac.ch')->assertStatus(202);
        $code = $this->codeEnvoye('Marie@Lac.ch');

        $this->assertNotNull($code);
        $this->verifier('Marie@Lac.ch', $code)->assertSuccessful();
        $this->assertSame(1, User::query()->where('email', 'Marie@Lac.ch')->count());
        $this->assertNotNull($invitation->fresh()->utilisee_le);
    }

    public function test_une_adresse_invitee_dans_l_equipe_d_une_clinique_admise_cree_son_compte(): void
    {
        $this->inviterDansEquipe($this->cliniqueAdmise(), 'lea@lac.ch');

        $this->demanderCode('lea@lac.ch')->assertStatus(202);

        $this->verifier('lea@lac.ch', (string) $this->codeEnvoye('lea@lac.ch'))->assertSuccessful();
    }

    /** Une clinique inscrite mais pas encore vérifiée ne fait entrer personne. */
    public function test_une_invitation_d_equipe_d_une_clinique_non_verifiee_ne_suffit_pas(): void
    {
        $this->inviterDansEquipe(Organisation::factory()->enAttente()->create(), 'lea@lac.ch');

        $this->demanderCode('lea@lac.ch')->assertStatus(202);

        $this->assertNull($this->codeEnvoye('lea@lac.ch'));
    }

    /** Une invitation d'équipe annulée ou expirée ne compte plus. */
    public function test_une_invitation_d_equipe_expiree_ne_suffit_pas(): void
    {
        $this->inviterDansEquipe($this->cliniqueAdmise(), 'lea@lac.ch', '-1 day');

        $this->demanderCode('lea@lac.ch')->assertStatus(202);

        $this->assertNull($this->codeEnvoye('lea@lac.ch'));
    }

    public function test_une_adresse_sur_une_fiche_client_d_une_clinique_admise_cree_son_compte(): void
    {
        Client::factory()->create(['organisation_id' => $this->cliniqueAdmise()->id, 'email' => 'Sophie.Muller@Exemple.ch']);

        $this->demanderCode('sophie.muller@exemple.ch')->assertStatus(202);

        $this->verifier('sophie.muller@exemple.ch', (string) $this->codeEnvoye('sophie.muller@exemple.ch'))->assertSuccessful();
    }

    public function test_une_fiche_client_d_une_clinique_non_verifiee_ne_suffit_pas(): void
    {
        Client::factory()->create(['organisation_id' => Organisation::factory()->enAttente()->create()->id, 'email' => 'sophie@exemple.ch']);

        $this->demanderCode('sophie@exemple.ch')->assertStatus(202);

        $this->assertNull($this->codeEnvoye('sophie@exemple.ch'));
    }

    public function test_un_compte_qui_existe_deja_se_connecte_toujours(): void
    {
        $paul = User::factory()->create(['email' => 'paul@exemple.ch']);
        $code = null;

        $this->demanderCode('paul@exemple.ch')->assertStatus(202);
        Notification::assertSentTo($paul, CodeEmailNotification::class, function (CodeEmailNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $this->verifier('paul@exemple.ch', (string) $code)->assertSuccessful();
        Mail::assertNotSent(AccesSurInvitation::class);
    }

    public function test_a_false_tout_le_monde_cree_un_compte(): void
    {
        config(['plateforme.acces_sur_invitation' => false]);

        $this->demanderCode('paul@exemple.ch')->assertStatus(202);

        $this->verifier('paul@exemple.ch', (string) $this->codeEnvoye('paul@exemple.ch'))->assertSuccessful();
        Mail::assertNotSent(AccesSurInvitation::class);
    }

    /** Sans la variable, l'accès est sur invitation : un oubli au déploiement ne l'ouvre pas. */
    public function test_le_reglage_ferme_l_acces_par_defaut(): void
    {
        $avant = [getenv('ACCES_SUR_INVITATION'), $_ENV['ACCES_SUR_INVITATION'] ?? null, $_SERVER['ACCES_SUR_INVITATION'] ?? null];
        putenv('ACCES_SUR_INVITATION');
        unset($_ENV['ACCES_SUR_INVITATION'], $_SERVER['ACCES_SUR_INVITATION']);

        try {
            $this->assertTrue((require config_path('plateforme.php'))['acces_sur_invitation']);
        } finally {
            if ($avant[0] !== false) {
                putenv('ACCES_SUR_INVITATION='.$avant[0]);
            }
            if ($avant[1] !== null) {
                $_ENV['ACCES_SUR_INVITATION'] = $avant[1];
            }
            if ($avant[2] !== null) {
                $_SERVER['ACCES_SUR_INVITATION'] = $avant[2];
            }
        }
    }
}
