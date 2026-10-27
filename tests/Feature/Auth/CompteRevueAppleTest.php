<?php

namespace Tests\Feature\Auth;

use App\Mail\AccesSurInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Compte de revue d'Apple (TestFlight externe, décision de Jim, 27.10.2026) :
 * l'adresse de revue se connecte avec un code fixe de 8 chiffres, rangé comme
 * secret dans Laravel Cloud, sans e-mail (personne ne lit cette boîte), même
 * pendant l'accès sur invitation. Espace propriétaire seul, sans clinique.
 */
class CompteRevueAppleTest extends TestCase
{
    use RefreshDatabase;

    private const string ADRESSE = 'revue@veterinary.app';

    private const string CODE = '24681357';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'plateforme.acces_sur_invitation' => true,
            'plateforme.revue_apple.email' => self::ADRESSE,
            'plateforme.revue_apple.code' => self::CODE,
        ]);
        Mail::fake();
        Notification::fake();
    }

    private function demander(string $email): void
    {
        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $email])->assertStatus(202);
    }

    public function test_l_adresse_de_revue_se_connecte_avec_le_code_fixe_et_cree_son_compte(): void
    {
        $this->demander('Revue@Veterinary.app');

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'Revue@Veterinary.app', 'code' => self::CODE])
            ->assertSuccessful()
            ->assertJsonPath('data.clinique', null);
        $this->assertTrue(User::query()->whereRaw('lower(email) = ?', [self::ADRESSE])->exists());
    }

    public function test_aucun_email_ne_part_vers_l_adresse_de_revue(): void
    {
        $this->demander(self::ADRESSE);

        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_un_mauvais_code_est_refuse_avec_les_memes_limites(): void
    {
        $this->demander(self::ADRESSE);

        foreach (range(1, 5) as $essai) {
            $this->postJson('/api/v1/auth/code-email/verifier', ['email' => self::ADRESSE, 'code' => '11111111'])
                ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Code invalide ou expiré.']);
        }
        // Cinq essais faux : le code est effacé, même le bon ne passe plus sans en redemander un
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => self::ADRESSE, 'code' => self::CODE])->assertUnprocessable();
        $this->assertFalse(User::query()->where('email', self::ADRESSE)->exists());
    }

    /** Sans code réglé, ou un code qui n'a pas 8 chiffres : l'adresse est traitée comme les autres. */
    public function test_sans_reglage_valable_rien_ne_change(): void
    {
        foreach ([null, '1234', 'abcdefgh'] as $code) {
            config(['plateforme.revue_apple.code' => $code]);
            Mail::fake();

            $this->demander(self::ADRESSE);

            // Non invitée : « sur invitation » au lieu d'un code, comme toute adresse
            Mail::assertSent(AccesSurInvitation::class);
        }
    }

    public function test_le_code_fixe_ne_sert_qu_a_l_adresse_de_revue(): void
    {
        config(['plateforme.acces_sur_invitation' => false]);
        $this->demander('paul@exemple.ch');

        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'paul@exemple.ch', 'code' => self::CODE])->assertUnprocessable();
        $this->assertFalse(User::query()->where('email', 'paul@exemple.ch')->exists());
    }
}
