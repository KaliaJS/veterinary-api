<?php

namespace Tests\Feature\Auth;

use App\Auth\CalculateurPermissions;
use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

class AuthentificationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_verifie_si_un_email_est_connu(): void
    {
        User::factory()->create(['email' => 'a.meyer@deuxrives.ch']);

        $this->postJson('/api/v1/auth/email', ['email' => 'a.meyer@deuxrives.ch'])
            ->assertOk()
            ->assertJson(['known' => true]);

        $this->postJson('/api/v1/auth/email', ['email' => 'inconnu@exemple.ch'])
            ->assertOk()
            ->assertJson(['known' => false]);
    }

    public function test_le_code_email_permet_de_se_connecter(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'a.meyer@deuxrives.ch']);
        Employe::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $user->email])
            ->assertStatus(202);

        $code = null;
        Notification::assertSentTo($user, CodeEmailNotification::class, function (CodeEmailNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $reponse = $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
        ]);

        $reponse->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expire_le', 'user' => ['id', 'mail', 'first', 'last'], 'employe' => ['id', 'titre', 'role_libelle'], 'clinique' => ['id', 'nom'], 'cliniques', 'permissions']])
            ->assertJsonPath('data.user.mail', $user->email);

        // Le code est à usage unique.
        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Code invalide ou expiré.']);
    }

    /** Inscription par code e-mail : l'adresse inconnue reçoit un code, sa vérification crée le compte. */
    public function test_le_code_email_inscrit_un_email_inconnu(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'nouveau@exemple.ch'])
            ->assertStatus(202);

        $code = null;
        Notification::assertSentOnDemand(CodeEmailNotification::class, function (CodeEmailNotification $notification, array $canaux, AnonymousNotifiable $destinataire) use (&$code): bool {
            $code = $notification->code;

            return $destinataire->routes['mail'] === 'nouveau@exemple.ch';
        });

        $this->assertDatabaseMissing('users', ['email' => 'nouveau@exemple.ch']);

        // Un mauvais code ne crée rien.
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'nouveau@exemple.ch', 'code' => '00000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseMissing('users', ['email' => 'nouveau@exemple.ch']);

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'nouveau@exemple.ch', 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expire_le', 'user' => ['id', 'mail', 'first', 'last']]])
            ->assertJsonPath('data.user.mail', 'nouveau@exemple.ch')
            ->assertJsonPath('data.user.first', 'nouveau')
            // Sans passkey : le front en propose une dans la foulée
            ->assertJsonPath('data.user.a_une_passkey', false)
            ->assertJsonPath('data.employe', null)
            ->assertJsonPath('data.clinique', null)
            ->assertJsonPath('data.cliniques', [])
            ->assertJsonPath('data.permissions', []);

        $this->assertDatabaseHas('users', ['email' => 'nouveau@exemple.ch']);
        $this->assertNotNull(User::firstWhere('email', 'nouveau@exemple.ch')->email_verified_at);

        // Le code est à usage unique — et le compte n'est pas recréé.
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'nouveau@exemple.ch', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Code invalide ou expiré.']);
        $this->assertSame(1, User::where('email', 'nouveau@exemple.ch')->count());
    }

    public function test_un_mauvais_code_est_refuse(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        Employe::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $user->email]);

        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => '00000000',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Code invalide ou expiré.']);
    }

    /** Compte sans rattachement (inscrit, ou rattachements disparus) : session sans clinique. */
    public function test_un_compte_sans_rattachement_clinique_recoit_une_session_sans_clinique(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $code = $this->envoyerEtRecupererLeCode($user);

        $jeton = $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.employe', null)
            ->assertJsonPath('data.clinique', null)
            ->assertJsonPath('data.permissions', [])
            ->json('data.token');

        // La session elle-même est ouverte…
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.employe', null)
            ->assertJsonPath('data.clinique', null);

        $this->getJson('/api/v1/auth/passkeys', $this->enTetes($jeton))->assertOk()->assertJsonCount(0, 'data');

        $nouveau = $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.clinique', null)
            ->json('data.token');
        $this->assertNotSame($jeton, $nouveau);
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertUnauthorized();

        // … mais aucune route de domaine.
        $this->getJson('/api/v1/cliniques', $this->enTetes($nouveau))->assertForbidden();
        $this->getJson('/api/v1/patients', $this->enTetes($nouveau))->assertForbidden();

        $this->postJson('/api/v1/auth/deconnexion', [], $this->enTetes($nouveau))->assertNoContent();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($nouveau))->assertUnauthorized();
    }

    public function test_un_rattachement_multiple_propose_les_cliniques_et_un_choix_a_usage_unique(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $employeA = Employe::factory()->create(['user_id' => $user->id]);
        $cliniqueB = Clinique::factory()->create();
        $employeB = Employe::factory()->dans($cliniqueB)->create(['user_id' => $user->id]);

        $code = $this->envoyerEtRecupererLeCode($user);

        // Sans choix : la liste des cliniques et un jeton de choix, pas de
        // jeton d'API ; le code est consommé (23.10.2026), il ne se rejoue plus
        $reponse = $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('data.choix_clinique', true)
            ->assertJsonMissingPath('data.token')
            ->assertJsonPath('data.cliniques', [
                ['id' => $employeA->cliniques->sole()->id, 'nom' => $employeA->cliniques->sole()->nom],
                ['id' => $cliniqueB->id, 'nom' => $cliniqueB->nom],
            ]);
        $choix = $reponse->json('data.choix');
        $this->assertSame(48, strlen($choix));

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code, 'clinique_id' => $cliniqueB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        // Le choix donne le jeton de la clinique choisie, une seule fois
        $this->postJson('/api/v1/auth/choix-clinique', ['choix' => $choix, 'clinique_id' => $cliniqueB->id])
            ->assertOk()
            ->assertJsonPath('data.clinique.id', $cliniqueB->id)
            ->assertJsonPath('data.employe.id', $employeB->id);
        $this->postJson('/api/v1/auth/choix-clinique', ['choix' => $choix, 'clinique_id' => $cliniqueB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('choix');
    }

    public function test_un_choix_ne_mene_pas_a_une_clinique_etrangere_au_compte(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        Employe::factory()->create(['user_id' => $user->id]);
        Employe::factory()->dans(Clinique::factory()->create())->create(['user_id' => $user->id]);
        $code = $this->envoyerEtRecupererLeCode($user);

        $choix = $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code])->json('data.choix');

        $this->postJson('/api/v1/auth/choix-clinique', ['choix' => $choix, 'clinique_id' => Clinique::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
    }

    public function test_un_compte_sans_rattachement_consomme_son_code(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $code = $this->envoyerEtRecupererLeCode($user);

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code])
            ->assertOk()->assertJsonStructure(['data' => ['token']])->assertJsonPath('data.clinique', null);

        // Le code prouve l'adresse (inscription par passkey non vérifiée jusque-là).
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_le_jeton_expire(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();

        $this->travel((int) config('sanctum.expiration') + 1)->minutes();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertUnauthorized();
    }

    public function test_le_rafraichissement_emet_un_jeton_neuf_et_revoque_l_ancien(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();

        $reponse = $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expire_le', 'clinique', 'permissions']]);

        $nouveau = $reponse->json('data.token');

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertUnauthorized();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($nouveau))->assertOk();
    }

    public function test_la_deconnexion_revoque_le_jeton(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();

        $this->postJson('/api/v1/auth/deconnexion', [], $this->enTetes($jeton))->assertNoContent();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertUnauthorized();
    }

    public function test_moi_expose_la_session_complete(): void
    {
        ['user' => $user, 'employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton(null, [
            'nom' => 'Dr Anna Meyer',
            'initiales' => 'AM',
            'role_libelle' => 'Vétérinaire · Titulaire',
        ]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'expire_le',
                'user' => ['id', 'mail', 'first', 'last'],
                'employe' => ['id', 'nom', 'initiales', 'titre', 'role_libelle', 'role_id'],
                'clinique' => ['id', 'nom'],
                'cliniques',
                'permissions',
            ]])
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.mail', $user->email)
            ->assertJsonPath('data.employe.id', $employe->id)
            ->assertJsonPath('data.employe.titre', 'Dr')
            ->assertJsonPath('data.employe.role_libelle', 'Vétérinaire · Titulaire')
            ->assertJsonPath('data.employe.role_id', $employe->role_id)
            ->assertJsonPath('data.clinique.id', $employe->cliniques->sole()->id)
            ->assertJsonPath('data.cliniques', [['id' => $employe->cliniques->sole()->id, 'nom' => $employe->cliniques->sole()->nom]])
            ->assertJsonPath('data.permissions', app(CalculateurPermissions::class)->pour($employe->load(['role', 'derogations'])));
    }

    public function test_le_titre_est_derive_du_nom(): void
    {
        $this->assertSame('Dr', Employe::factory()->make(['nom' => 'Dr Anna Meyer'])->titre());
        $this->assertSame('Dre', Employe::factory()->make(['nom' => 'Dre S. Blanc'])->titre());
        $this->assertSame('Pr', Employe::factory()->make(['nom' => 'Pr. K. Ito'])->titre());
        $this->assertNull(Employe::factory()->make(['nom' => 'Léa Perrin'])->titre());
        $this->assertNull(Employe::factory()->make(['nom' => 'Drew Barry'])->titre());
    }

    public function test_les_permissions_de_moi_sont_lues_en_base(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))
            ->assertJsonFragment(['permissions' => app(CalculateurPermissions::class)->pour($employe->load(['role', 'derogations']))]);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Facturation,
            'est_accordee' => false,
        ]);

        $permissions = $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->json('data.permissions');

        $this->assertNotContains(DomainePermission::Facturation->value, $permissions);
    }

    public function test_le_changement_de_clinique_reemet_un_jeton_et_revoque_l_ancien(): void
    {
        ['user' => $user, 'employe' => $employeA, 'jeton' => $jeton] = $this->employeAvecJeton();
        $cliniqueB = Clinique::factory()->create();
        $employeB = Employe::factory()->asv()->dans($cliniqueB)->create(['user_id' => $user->id]);

        $reponse = $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $cliniqueB->id], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.clinique.id', $cliniqueB->id)
            ->assertJsonPath('data.employe.id', $employeB->id)
            ->assertJsonPath('data.employe.role_id', $employeB->role_id)
            ->assertJsonCount(2, 'data.cliniques');

        $nouveau = $reponse->json('data.token');

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertUnauthorized();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($nouveau))
            ->assertOk()
            ->assertJsonPath('data.clinique.id', $cliniqueB->id)
            ->assertJsonPath('data.employe.id', $employeB->id)
            ->assertJsonPath('data.employe.role_libelle', $employeB->role_libelle);

        $this->assertNotSame($employeA->id, $employeB->id);
    }

    public function test_le_changement_vers_une_clinique_etrangere_est_refuse_et_garde_le_jeton(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        $cliniqueEtrangere = Clinique::factory()->create();

        $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $cliniqueEtrangere->id], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinique_id');

        $this->postJson('/api/v1/auth/clinique', [], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinique_id');

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();
    }

    private function envoyerEtRecupererLeCode(User $user): string
    {
        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $user->email])->assertStatus(202);

        $code = null;
        Notification::assertSentTo($user, CodeEmailNotification::class, function (CodeEmailNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        return $code;
    }
}
