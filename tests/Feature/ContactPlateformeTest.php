<?php

namespace Tests\Feature;

use App\Mail\MessageContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Formulaire de contact (décision de Jim, 27.10.2026) : plus d'adresse
 * e-mail en clair sur le site, un formulaire protégé par Turnstile et
 * limité par adresse IP. Le message arrive à l'adresse de la plateforme,
 * avec l'adresse de la personne pour lui répondre ; cette adresse de
 * réception ne sort jamais vers le navigateur.
 */
class ContactPlateformeTest extends TestCase
{
    use RefreshDatabase;

    private const string RECEPTION = 'reception@exemple.ch';

    protected function setUp(): void
    {
        parent::setUp();
        config(['plateforme.contact_email' => self::RECEPTION, 'services.turnstile.secret' => 'secret-de-test']);
        Mail::fake();
        Http::fake(['challenges.cloudflare.com/*' => fn ($requete) => Http::response(['success' => $requete['response'] === 'jeton-valable'])]);
    }

    /** @return array<string, string> */
    private function message(array $autres = []): array
    {
        return [
            'nom' => 'Paul Rochat',
            'email' => 'paul@exemple.ch',
            'message' => 'Bonjour, je voudrais savoir quelles données vous gardez sur moi.',
            'captcha' => 'jeton-valable',
            ...$autres,
        ];
    }

    public function test_le_message_arrive_a_la_plateforme_avec_l_adresse_pour_repondre(): void
    {
        $reponse = $this->postJson('/api/v1/public/contact', $this->message())->assertStatus(202);

        Mail::assertSent(MessageContact::class, function (MessageContact $mail): bool {
            return $mail->hasTo(self::RECEPTION)
                && $mail->hasReplyTo('paul@exemple.ch', 'Paul Rochat')
                && str_contains($mail->render(), 'quelles données vous gardez sur moi')
                && str_contains($mail->render(), 'paul@exemple.ch');
        });
        // L'adresse de réception ne sort jamais vers le navigateur
        $this->assertStringNotContainsString(self::RECEPTION, $reponse->getContent());
    }

    /** E-mail en texte seul : le message arrive tel qu'écrit, apostrophes et chevrons compris, jamais interprété. */
    public function test_le_message_arrive_tel_qu_ecrit(): void
    {
        $this->postJson('/api/v1/public/contact', $this->message(['nom' => 'Léa D’Amico', 'message' => "J'aimerais un <b>rendez-vous</b> & une réponse."]))->assertStatus(202);

        Mail::assertSent(MessageContact::class, function (MessageContact $mail): bool {
            $texte = $mail->render();

            return str_contains($texte, "J'aimerais un <b>rendez-vous</b> & une réponse.")
                && str_contains($texte, 'Léa D’Amico')
                && ! str_contains($texte, '&#039;') && ! str_contains($texte, '&lt;');
        });
    }

    public function test_sans_turnstile_valable_rien_ne_part(): void
    {
        $this->postJson('/api/v1/public/contact', $this->message(['captcha' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['captcha' => 'Cochez la vérification anti-robot.']);
        $this->postJson('/api/v1/public/contact', $this->message(['captcha' => 'jeton-refuse']))
            ->assertUnprocessable()->assertJsonValidationErrors(['captcha' => 'La vérification anti-robot n’a pas abouti : réessayez.']);

        Mail::assertNothingSent();
    }

    public function test_nom_e_mail_et_message_sont_demandes(): void
    {
        $this->postJson('/api/v1/public/contact', ['captcha' => 'jeton-valable'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nom', 'email', 'message']);
        $this->postJson('/api/v1/public/contact', $this->message(['email' => 'pas-une-adresse']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_les_envois_sont_limites_par_adresse_ip(): void
    {
        foreach (range(1, 5) as $envoi) {
            $this->postJson('/api/v1/public/contact', $this->message(), ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(202);
        }

        $this->postJson('/api/v1/public/contact', $this->message(), ['REMOTE_ADDR' => '203.0.113.7'])
            ->assertStatus(429)->assertJsonPath('message', 'Trop de messages envoyés : réessayez dans une heure.');
        // Une autre adresse IP n'est pas touchée
        $this->postJson('/api/v1/public/contact', $this->message(), ['REMOTE_ADDR' => '203.0.113.8'])->assertStatus(202);
        Mail::assertSent(MessageContact::class, 6);
    }
}
