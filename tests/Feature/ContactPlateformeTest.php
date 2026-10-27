<?php

namespace Tests\Feature;

use App\Auth\EmetteurJetons;
use App\Mail\MessageContact;
use App\Models\User;
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

    /** Messages en français sous les champs (l'API n'a pas de traductions). */
    public function test_les_erreurs_sont_en_francais(): void
    {
        $this->postJson('/api/v1/public/contact', ['captcha' => 'jeton-valable'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nom' => 'Indiquez votre nom.',
                'email' => 'Indiquez votre adresse e-mail.',
                'message' => 'Écrivez votre message.',
            ]);
        $this->postJson('/api/v1/public/contact', $this->message(['email' => 'pas-une-adresse']))
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Cette adresse e-mail n’est pas valable.']);
    }

    /** L'envoi de l'e-mail échoue (Resend en panne) : un message clair, pas une erreur 500, et l'adresse de réception ne sort pas. */
    public function test_une_panne_de_l_envoi_donne_un_message_clair(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Resend en panne pour '.self::RECEPTION));

        $reponse = $this->postJson('/api/v1/public/contact', $this->message())
            ->assertStatus(503)
            ->assertJsonPath('message', 'Votre message n’est pas parti : réessayez dans quelques minutes.');
        $this->assertStringNotContainsString(self::RECEPTION, $reponse->getContent());
    }

    /** Un retour à la ligne dans le nom ne fabrique pas de fausse ligne dans le message. */
    public function test_un_retour_a_la_ligne_dans_le_nom_reste_sur_sa_ligne(): void
    {
        $this->postJson('/api/v1/public/contact', $this->message(['nom' => "Paul\r\nDe : faux@pirate.ch"]))->assertStatus(202);

        Mail::assertSent(MessageContact::class, function (MessageContact $mail): bool {
            $lignes = preg_split('/\R/', $mail->render());

            return $mail->nom === 'Paul De : faux@pirate.ch'
                && count(array_filter($lignes, fn ($ligne) => str_starts_with($ligne, 'De :'))) === 1;
        });
    }

    /** Décision de Jim (27.10.2026) : une personne connectée écrit sans Turnstile (l'app iPhone ne peut pas l'afficher). */
    public function test_une_personne_connectee_ecrit_sans_turnstile(): void
    {
        $user = User::factory()->create(['name' => 'Paul Rochat', 'email' => 'paul@exemple.ch']);
        $jeton = app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken;

        $this->postJson('/api/v1/public/contact', $this->message(['captcha' => null]), ['Authorization' => 'Bearer '.$jeton])->assertStatus(202);

        Mail::assertSent(MessageContact::class, fn (MessageContact $mail) => $mail->hasReplyTo('paul@exemple.ch'));
    }

    /** Ses envois sont limités par compte, quelle que soit son adresse IP. */
    public function test_les_envois_d_une_personne_connectee_sont_limites_par_compte(): void
    {
        $jeton = app(EmetteurJetons::class)->emettreSansClinique(User::factory()->create())->plainTextToken;

        foreach (range(1, 5) as $envoi) {
            $this->postJson('/api/v1/public/contact', $this->message(['captcha' => null]), ['Authorization' => 'Bearer '.$jeton, 'REMOTE_ADDR' => '203.0.113.'.$envoi])->assertStatus(202);
        }

        $this->postJson('/api/v1/public/contact', $this->message(['captcha' => null]), ['Authorization' => 'Bearer '.$jeton, 'REMOTE_ADDR' => '203.0.113.99'])
            ->assertStatus(429)->assertJsonPath('message', 'Trop de messages envoyés : réessayez dans une heure.');
    }

    /** Un jeton faux ou expiré ne dispense pas de Turnstile. */
    public function test_un_jeton_invalide_ne_dispense_pas_de_turnstile(): void
    {
        $this->postJson('/api/v1/public/contact', $this->message(['captcha' => null]), ['Authorization' => 'Bearer 1|faux'])
            ->assertUnprocessable()->assertJsonValidationErrors('captcha');

        Mail::assertNothingSent();
    }
}
