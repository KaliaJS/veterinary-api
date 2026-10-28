<?php

namespace Tests\Feature\Auth;

use App\Models\InvitationAcces;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * En MySQL (utf8mb4_unicode_ci), « paul@gmaíl.com » vaut « paul@gmail.com »,
 * alors que PHP les distingue (vérification du 28.10.2026) : une adresse
 * accentuée prendrait l'invitation ou le compte d'une autre. Les adresses
 * des comptes s'écrivent donc sans accent ni caractère spécial.
 */
class AdressesSansAccentTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string REFUS = 'Écrivez l’adresse e-mail sans accent ni caractère spécial.';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
    }

    public function test_le_code_de_connexion_refuse_une_adresse_accentuee(): void
    {
        config(['plateforme.acces_sur_invitation' => true]);
        InvitationAcces::query()->create(['email' => 'paul@gmail.com']);

        foreach (['paul@gmaíl.com', 'pаul@gmail.com', "pa\u{200B}ul@gmail.com"] as $adresse) {
            $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $adresse])
                ->assertUnprocessable()->assertJsonValidationErrors(['email' => self::REFUS]);
            $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $adresse, 'code' => '12345678'])
                ->assertUnprocessable()->assertJsonValidationErrors(['email' => self::REFUS]);
        }

        $this->assertSame(0, User::query()->count());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        // L'adresse elle-même passe
        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'Paul@Gmail.com'])->assertStatus(202);
    }

    public function test_une_fiche_client_ou_une_invitation_d_equipe_refuse_une_adresse_accentuee(): void
    {
        ['jeton' => $jeton] = $this->administrateurAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->postJson('/api/v1/clients', ['civilite' => 'M.', 'prenom' => 'Paul', 'nom' => 'Rochat', 'email' => 'paul@gmaíl.com'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => self::REFUS]);
        $this->postJson('/api/v1/equipe/invitations', ['email' => 'lea@gmaíl.com'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => self::REFUS]);
    }
}
