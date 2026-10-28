<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Database\Factories\PasskeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Limites de la connexion (23.10.2026) : par IP seule en plus du couple
 * adresse + IP, envoi de codes par destinataire toutes IP confondues,
 * essais comptés avant la vérification et code effacé au-delà.
 */
class LimitesConnexionTest extends TestCase
{
    use EmetDesJetonsApi;
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

    /** Par adresse et par IP (24.10.2026) : trois en quinze minutes, dix par jour ; la victime, ailleurs, reçoit le sien. */
    public function test_les_envois_d_une_ip_ne_privent_pas_la_victime_de_son_code(): void
    {
        Notification::fake();
        $envoyer = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'victime@exemple.ch']);

        foreach (range(1, 3) as $i) {
            $envoyer('198.51.100.1')->assertStatus(202);
        }
        $envoyer('198.51.100.1')->assertTooManyRequests();
        $envoyer('203.0.113.7')->assertStatus(202);

        // Dix par jour depuis la même IP, même espacés : 3 + 3 + 3 + 1
        foreach ([3, 3, 1] as $nombre) {
            $this->travel(16)->minutes();
            foreach (range(1, $nombre) as $i) {
                $envoyer('198.51.100.1')->assertStatus(202);
            }
        }
        $envoyer('198.51.100.1')->assertTooManyRequests();
        $envoyer('203.0.113.7')->assertStatus(202);
    }

    /** Toutes IP confondues, plus haut : dix en quinze minutes, cinquante par jour. */
    public function test_une_boite_n_est_pas_bombardee_depuis_beaucoup_d_ip(): void
    {
        Notification::fake();
        $envoyer = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'victime@exemple.ch']);

        foreach (range(1, 10) as $i) {
            $envoyer("198.51.100.{$i}")->assertStatus(202);
        }
        $envoyer('198.51.100.11')->assertTooManyRequests();

        foreach (range(1, 4) as $vague) {
            $this->travel(16)->minutes();
            foreach (range(1, 10) as $i) {
                $envoyer('192.0.2.'.($vague * 10 + $i))->assertStatus(202);
            }
        }
        $this->travel(16)->minutes();
        $envoyer('203.0.113.200')->assertTooManyRequests();
    }

    /** Derrière le répartiteur de charge, l'IP du client compte, pas celle du répartiteur. */
    public function test_derriere_le_repartiteur_l_ip_du_client_compte(): void
    {
        Notification::fake();
        $envoyer = fn (string $client) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-For', $client)
            ->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'victime@exemple.ch']);

        // Sans proxys de confiance : tout le monde a l'adresse du répartiteur
        foreach (range(1, 3) as $i) {
            $envoyer('198.51.100.1')->assertStatus(202);
        }
        $envoyer('203.0.113.7')->assertTooManyRequests();

        config(['app.proxies_de_confiance' => '*']);
        $this->travel(16)->minutes();
        foreach (range(1, 3) as $i) {
            $envoyer('198.51.100.1')->assertStatus(202);
        }
        $envoyer('198.51.100.1')->assertTooManyRequests();
        $envoyer('203.0.113.7')->assertStatus(202);
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

    /** Dans les dix minutes de validité : le code neuf rend les cinq essais, sans attendre l'expiration. */
    public function test_un_code_neuf_rend_les_cinq_essais_tout_de_suite(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $code = $this->envoyerEtRecupererLeCode($user);
        $faux = $code === '11111111' ? '22222222' : '11111111';

        foreach (range(1, 5) as $essai) {
            $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $faux])->assertUnprocessable();
        }

        $this->travel(1)->minutes();
        Notification::fake();
        $neuf = $this->envoyerEtRecupererLeCode($user);
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $user->email, 'code' => $neuf])->assertOk();
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
            ->assertUnprocessable()->assertJsonValidationErrors('code')
            // Option A de Jim (28.10.2026) : la fenêtre reconnaît le cas et mène à la passkey
            ->assertJsonValidationErrors('passkey');
    }

    /** Décision de Jim (24.10.2026) : un administrateur de clinique qui a une passkey garde la connexion par code. */
    public function test_un_administrateur_qui_a_une_passkey_se_connecte_aussi_par_code(): void
    {
        Notification::fake();
        ['user' => $administrateur] = $this->administrateurAvecJeton(Clinique::factory()->create());
        PasskeyFactory::new()->create(['user_id' => $administrateur->id]);

        $code = $this->envoyerEtRecupererLeCode($administrateur);

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $administrateur->email, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.mail', $administrateur->email);
        $this->assertSame(1, $administrateur->passkeys()->count());
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

    /**
     * Sur Laravel Cloud, la bordure est Cloudflare et l'app n'est joignable
     * que par elle : l'adresse du visiteur est celle de CF-Connecting-IP, que
     * Cloudflare écrit lui-même. Un X-Forwarded-For forgé, que la bordure
     * allonge sans le remplacer, ne change plus de compteur (27.10.2026).
     */
    public function test_sur_laravel_cloud_l_adresse_vient_de_cloudflare_pas_d_un_en_tete_forge(): void
    {
        Notification::fake();
        config(['app.proxies_de_confiance' => '*', 'app.ip_par_cloudflare' => true]);
        $envoyer = fn (string $forge) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => $forge.', 198.51.100.1', 'CF-Connecting-IP' => '198.51.100.1', 'X-Forwarded-Proto' => 'https'])
            ->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'victime@exemple.ch']);

        foreach (range(1, 3) as $i) {
            $envoyer('1.1.1.'.$i)->assertStatus(202);
        }
        // Une quatrième adresse forgée : même visiteur pour Cloudflare, même compteur
        $envoyer('1.1.1.4')->assertTooManyRequests();
    }

    /** Sans l'en-tête de Cloudflare (local, tests), rien ne change. */
    public function test_sans_l_en_tete_de_cloudflare_rien_ne_change(): void
    {
        config(['app.proxies_de_confiance' => '*', 'app.ip_par_cloudflare' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withHeader('X-Forwarded-For', '198.51.100.9')
            ->getJson('/api/v1/public/cliniques');

        $this->assertSame('198.51.100.9', request()->ip());
    }

    /** Sans réglage (ou une ligne vide recopiée de .env.example) : actif sur Laravel Cloud, détecté comme le fait Laravel. */
    public function test_sans_reglage_la_lecture_chez_cloudflare_suit_laravel_cloud(): void
    {
        Notification::fake();
        config(['app.proxies_de_confiance' => '*', 'app.ip_par_cloudflare' => null]);
        $_SERVER['LARAVEL_CLOUD'] = '1';
        $envoyer = fn (string $forge) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => $forge.', 198.51.100.1', 'CF-Connecting-IP' => '198.51.100.1'])
            ->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'victime@exemple.ch']);

        try {
            foreach (range(1, 3) as $i) {
                $envoyer('1.1.1.'.$i)->assertStatus(202);
            }
            $envoyer('1.1.1.4')->assertTooManyRequests();
        } finally {
            unset($_SERVER['LARAVEL_CLOUD']);
        }
    }

    /** Hors de Laravel Cloud et sans réglage, CF-Connecting-IP n'est pas cru (n'importe qui pourrait l'écrire). */
    public function test_hors_de_laravel_cloud_cf_connecting_ip_n_est_pas_cru(): void
    {
        config(['app.proxies_de_confiance' => null, 'app.ip_par_cloudflare' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->withHeader('CF-Connecting-IP', '198.51.100.1')
            ->getJson('/api/v1/public/cliniques');

        $this->assertSame('192.0.2.10', request()->ip());
    }

    /** Avec une liste précise de proxys de confiance, l'adresse vient de Cloudflare et https reste reconnu. */
    public function test_avec_une_liste_de_proxys_https_reste_reconnu(): void
    {
        config(['app.proxies_de_confiance' => '10.0.0.1', 'app.ip_par_cloudflare' => true]);

        // Reçue en http par le proxy (la bordure a terminé le https)
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTPS' => 'off'])
            ->withHeaders(['CF-Connecting-IP' => '198.51.100.1', 'X-Forwarded-For' => '1.1.1.1, 198.51.100.1', 'X-Forwarded-Proto' => 'https'])
            ->getJson('http://localhost/api/v1/public/cliniques');

        $this->assertSame('198.51.100.1', request()->ip());
        $this->assertTrue(request()->isSecure());
    }

    /** IP_PAR_CLOUDFLARE=false coupe la lecture, même sur Laravel Cloud. */
    public function test_ip_par_cloudflare_faux_coupe_la_lecture_meme_sur_laravel_cloud(): void
    {
        config(['app.proxies_de_confiance' => '*', 'app.ip_par_cloudflare' => false]);
        $_SERVER['LARAVEL_CLOUD'] = '1';

        try {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->withHeaders(['CF-Connecting-IP' => '198.51.100.1', 'X-Forwarded-For' => '198.51.100.9'])
                ->getJson('/api/v1/public/cliniques');

            $this->assertSame('198.51.100.9', request()->ip());
        } finally {
            unset($_SERVER['LARAVEL_CLOUD']);
        }
    }
}
