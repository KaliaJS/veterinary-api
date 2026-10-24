<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Limites de la connexion (23.10.2026) : par IP seule en plus du couple
 * adresse + IP, envoi de codes par destinataire toutes IP confondues,
 * essais comptés avant la vérification et code effacé au-delà.
 */
class LimitesConnexionTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_ip_ne_balaie_pas_des_milliers_d_adresses(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->postJson('/api/v1/auth/passkey/options', ['email' => "personne{$i}@exemple.ch"])->assertOk();
        }

        $this->postJson('/api/v1/auth/passkey/options', ['email' => 'personne31@exemple.ch'])->assertTooManyRequests();

        // Une autre machine n'est pas touchée
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/auth/passkey/options', ['email' => 'personne31@exemple.ch'])->assertOk();
    }

    public function test_une_boite_ne_recoit_pas_plus_de_trois_codes_en_quinze_minutes_quelle_que_soit_l_ip(): void
    {
        Notification::fake();

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'victime@exemple.ch'])->assertStatus(202);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
            ->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'victime@exemple.ch'])->assertTooManyRequests();
    }

    public function test_au_cinquieme_mauvais_code_le_bon_code_ne_marche_plus(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $code = $this->envoyerEtRecupererLeCode($user);
        $faux = $code === '11111111' ? '22222222' : '11111111';

        foreach (range(1, 5) as $essai) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$essai}"])
                ->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $faux])
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    /** De faux codes depuis ailleurs ne ferment pas la connexion de la personne pour la journée. */
    public function test_de_faux_codes_ne_bloquent_pas_la_victime_et_un_code_neuf_rend_ses_essais(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $code = $this->envoyerEtRecupererLeCode($user);
        $faux = $code === '11111111' ? '22222222' : '11111111';

        // Un tiers, depuis des IP qui changent, tape de faux codes sur cette adresse
        foreach (range(1, 25) as $essai) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$essai}"])
                ->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $faux]);
        }

        // La personne demande un code neuf et se connecte depuis chez elle
        $this->travel(16)->minutes();
        $neuf = $this->envoyerEtRecupererLeCode($user);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.200'])
            ->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $neuf])
            ->assertOk();
    }

    public function test_le_bon_code_passe_apres_quelques_erreurs(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $code = $this->envoyerEtRecupererLeCode($user);
        $faux = $code === '11111111' ? '22222222' : '11111111';

        foreach (range(1, 3) as $essai) {
            $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $faux])->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Code invalide ou expiré.']);
        }

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code])->assertOk();
    }

    public function test_un_compte_pris_d_avance_par_passkey_perd_passkeys_et_sessions_quand_le_titulaire_prouve_l_adresse(): void
    {
        Notification::fake();
        // Quelqu'un a inscrit l'adresse de la victime avec sa propre passkey
        $user = User::factory()->create(['email' => 'victime@exemple.ch', 'email_verified_at' => null]);
        DB::table('passkeys')->insert(['user_id' => $user->id, 'nom' => 'Clé de l’attaquant', 'credential_id' => 'cle-attaquant', 'donnees' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $user->createToken('attaquant');

        $code = $this->envoyerEtRecupererLeCode($user);
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $code])->assertOk();

        $this->assertSame(0, $user->passkeys()->count());
        $this->assertNotNull($user->fresh()->email_verified_at);
        // Seule reste la session que la victime vient d'ouvrir
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_un_moderateur_qui_a_une_passkey_ne_se_connecte_pas_par_code(): void
    {
        Notification::fake();
        $jim = User::factory()->create();
        $jim->forceFill(['est_moderateur' => true])->save();
        DB::table('passkeys')->insert(['user_id' => $jim->id, 'nom' => 'Mac', 'credential_id' => 'cle-jim', 'donnees' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $code = $this->envoyerEtRecupererLeCode($jim);

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $jim->email, 'code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
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
