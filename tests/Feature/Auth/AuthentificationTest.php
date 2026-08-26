<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
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
            ->assertJsonStructure(['data' => ['token', 'expire_le', 'user' => ['mail', 'first', 'last'], 'clinique' => ['id', 'nom'], 'permissions']])
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

    public function test_un_rattachement_multiple_exige_le_choix_de_la_clinique(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $employeA = Employe::factory()->create(['user_id' => $user->id]);
        $cliniqueB = Clinique::factory()->create();
        Employe::factory()->create(['user_id' => $user->id, 'clinique_id' => $cliniqueB->id]);

        $envoyerEtRecupererCode = function () use ($user, &$code): void {
            $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $user->email]);
            Notification::assertSentTo($user, CodeEmailNotification::class, function (CodeEmailNotification $notification) use (&$code): bool {
                $code = $notification->code;

                return true;
            });
        };

        $envoyerEtRecupererCode();
        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('clinique_id');

        $envoyerEtRecupererCode();
        $this->postJson('/api/v1/auth/code-email/verifier', [
            'email' => $user->email,
            'code' => $code,
            'clinique_id' => $employeA->clinique_id,
        ])->assertOk()->assertJsonPath('data.clinique.id', $employeA->clinique_id);
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

    public function test_moi_expose_la_session(): void
    {
        ['user' => $user, 'employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.user.mail', $user->email)
            ->assertJsonPath('data.clinique.id', $employe->clinique_id);
    }
}
