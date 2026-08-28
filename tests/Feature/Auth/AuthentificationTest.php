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
        ])->assertUnprocessable();
    }

    public function test_l_envoi_de_code_reste_muet_pour_un_email_inconnu(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'inconnu@exemple.ch'])
            ->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_un_mauvais_code_est_refuse(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        Employe::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $user->email]);

        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => '000000',
        ])->assertUnprocessable();
    }

    public function test_un_compte_sans_rattachement_clinique_ne_recoit_pas_de_jeton(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $user->email]);

        $code = null;
        Notification::assertSentTo($user, CodeEmailNotification::class, function (CodeEmailNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_un_rattachement_multiple_propose_les_cliniques_sans_consommer_le_code(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $employeA = Employe::factory()->create(['user_id' => $user->id]);
        $cliniqueB = Clinique::factory()->create();
        $employeB = Employe::factory()->create(['user_id' => $user->id, 'clinique_id' => $cliniqueB->id]);

        $code = $this->envoyerEtRecupererLeCode($user);

        // Sans choix : la liste des cliniques, pas de jeton — et le code
        // n'est pas consommé (le rattachement est contrôlé avant).
        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('data.choix_clinique', true)
            ->assertJsonMissingPath('data.token')
            ->assertExactJson(['data' => [
                'choix_clinique' => true,
                'cliniques' => [
                    ['id' => $employeA->clinique_id, 'nom' => $employeA->clinique->nom],
                    ['id' => $employeB->clinique_id, 'nom' => $cliniqueB->nom],
                ],
            ]]);

        // Une clinique étrangère au compte : refusée, code toujours valide.
        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
            'clinique_id' => Clinique::factory()->create()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('clinique_id');

        // Le même code, avec le choix : jeton émis pour cette clinique.
        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
            'clinique_id' => $employeB->clinique_id,
        ])
            ->assertOk()
            ->assertJsonPath('data.clinique.id', $employeB->clinique_id)
            ->assertJsonPath('data.employe.id', $employeB->id);

        // … et seulement maintenant, il est consommé.
        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
            'clinique_id' => $employeA->clinique_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_un_compte_sans_rattachement_ne_consomme_pas_son_code(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $code = $this->envoyerEtRecupererLeCode($user);

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        // Rattaché entre-temps (embauche) : le code d'origine sert encore.
        Employe::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code])
            ->assertOk()->assertJsonStructure(['data' => ['token']]);
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
            ->assertJsonPath('data.clinique.id', $employe->clinique_id)
            ->assertJsonPath('data.cliniques', [['id' => $employe->clinique_id, 'nom' => $employe->clinique->nom]])
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
        $employeB = Employe::factory()->asv()->create(['user_id' => $user->id, 'clinique_id' => $cliniqueB->id]);

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
