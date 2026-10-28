<?php

namespace Tests\Feature;

use App\Auth\EmetteurJetons;
use App\Mail\MessageContact;
use App\Models\User;
use App\Support\CleIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    /* ——— Défauts faibles restants (demande de Jim, 28.10.2026) ——— */

    public function test_tous_les_messages_d_erreur_sont_en_francais(): void
    {
        $this->postJson('/api/v1/public/contact', $this->message(['nom' => ['a'], 'message' => ['b'], 'captcha' => ['c']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nom' => 'Indiquez votre nom.', 'message' => 'Écrivez votre message.', 'captcha' => 'La vérification anti-robot n’a pas abouti : réessayez.']);
        $this->postJson('/api/v1/public/contact', $this->message(['captcha' => str_repeat('x', 4097), 'nom' => str_repeat('n', 121), 'message' => str_repeat('m', 5001), 'email' => str_repeat('e', 190).'@exemple.ch']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'captcha' => 'La vérification anti-robot n’a pas abouti : réessayez.',
                'nom' => 'Ce nom est trop long.',
                'message' => 'Ce message est trop long (5000 caractères au plus).',
                'email' => 'Cette adresse e-mail est trop longue.',
            ]);
    }

    public function test_un_separateur_de_ligne_unicode_dans_le_nom_ne_fabrique_pas_de_fausse_ligne(): void
    {
        foreach (["Paul\u{2028}De : faux@pirate.ch", "Paul\u{2029}De : faux@pirate.ch", "Paul\u{0085}De : faux@pirate.ch"] as $nom) {
            $this->postJson('/api/v1/public/contact', $this->message(['nom' => $nom]))->assertStatus(202);
        }

        Mail::assertSent(MessageContact::class, 3);
        // Chacun des trois messages, pas un seul d'entre eux
        foreach (Mail::sent(MessageContact::class) as $mail) {
            $lignes = preg_split('/\R/u', $mail->render());
            $this->assertCount(1, array_filter($lignes, fn ($ligne) => str_starts_with($ligne, 'De :')));
        }
    }

    public function test_un_nom_vide_apres_nettoyage_est_refuse(): void
    {
        $this->postJson('/api/v1/public/contact', $this->message(['nom' => "\u{0007}\u{0007}"]))
            ->assertUnprocessable()->assertJsonValidationErrors(['nom' => 'Indiquez votre nom.']);

        Mail::assertNothingSent();
    }

    public function test_les_fautes_de_saisie_ne_comptent_pas_dans_la_limite(): void
    {
        foreach (range(1, 6) as $essai) {
            $this->postJson('/api/v1/public/contact', ['captcha' => 'jeton-valable'], ['REMOTE_ADDR' => '203.0.113.7'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/public/contact', $this->message(), ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(202);
    }

    public function test_en_ipv6_la_limite_porte_sur_le_prefixe_64(): void
    {
        foreach (range(1, 5) as $envoi) {
            $this->postJson('/api/v1/public/contact', $this->message(), ['REMOTE_ADDR' => '2001:db8:1:2::'.$envoi])->assertStatus(202);
        }

        $this->postJson('/api/v1/public/contact', $this->message(), ['REMOTE_ADDR' => '2001:db8:1:2:ffff:ffff:ffff:ffff'])->assertStatus(429);
        $this->postJson('/api/v1/public/contact', $this->message(), ['REMOTE_ADDR' => '2001:db8:1:3::1'])->assertStatus(202);
    }

    public function test_le_message_d_une_personne_connectee_dit_de_quel_compte_il_vient(): void
    {
        $user = User::factory()->create(['email' => 'compte@exemple.ch']);
        $jeton = app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken;

        $this->postJson('/api/v1/public/contact', $this->message(['captcha' => null, 'email' => 'autre@exemple.ch']), ['Authorization' => 'Bearer '.$jeton])->assertStatus(202);

        Mail::assertSent(MessageContact::class, fn (MessageContact $mail) => str_contains($mail->render(), "Compte Veterinary : n° {$user->id}, compte@exemple.ch"));
    }

    public function test_deux_comptes_sur_la_meme_adresse_ip_ont_chacun_leur_limite(): void
    {
        $paul = app(EmetteurJetons::class)->emettreSansClinique(User::factory()->create())->plainTextToken;
        $lea = app(EmetteurJetons::class)->emettreSansClinique(User::factory()->create())->plainTextToken;

        foreach (range(1, 5) as $envoi) {
            $this->postJson('/api/v1/public/contact', $this->message(['captcha' => null]), ['Authorization' => 'Bearer '.$paul, 'REMOTE_ADDR' => '203.0.113.7'])->assertStatus(202);
            $this->app['auth']->forgetGuards();
        }

        $this->postJson('/api/v1/public/contact', $this->message(['captcha' => null]), ['Authorization' => 'Bearer '.$lea, 'REMOTE_ADDR' => '203.0.113.7'])->assertStatus(202);
    }

    public function test_sans_cle_secrete_turnstile_rien_ne_part(): void
    {
        config(['services.turnstile.secret' => '']);

        $this->postJson('/api/v1/public/contact', $this->message())
            ->assertUnprocessable()->assertJsonValidationErrors('captcha');

        Mail::assertNothingSent();
    }

    public function test_au_dela_de_20_messages_par_jour_le_formulaire_refuse(): void
    {
        foreach (range(1, 4) as $heure) {
            foreach (range(1, 5) as $envoi) {
                $this->postJson('/api/v1/public/contact', $this->message())->assertStatus(202);
            }
            $this->travel(61)->minutes();
        }

        $this->postJson('/api/v1/public/contact', $this->message())
            ->assertStatus(429)->assertJsonPath('message', 'Trop de messages envoyés aujourd’hui : réessayez demain.');
    }

    public function test_sans_adresse_de_reception_le_formulaire_repond_clairement(): void
    {
        config(['plateforme.contact_email' => '']);

        $this->postJson('/api/v1/public/contact', $this->message())
            ->assertStatus(503)->assertJsonPath('message', 'Le formulaire de contact n’est pas disponible pour l’instant.');
        Mail::assertNothingSent();
    }

    /* ——— Vérification du 28.10.2026 ——— */

    /** Un séparateur de ligne Unicode dans l'adresse n'invente pas de ligne dans le message. */
    public function test_l_adresse_avec_un_separateur_de_ligne_est_refusee(): void
    {
        foreach (["paul@exemple.ch\u{2028}Compte-Veterinary:12", "paul\u{2029}x@exemple.ch"] as $adresse) {
            $this->postJson('/api/v1/public/contact', $this->message(['email' => $adresse]))
                ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Cette adresse e-mail n’est pas valable.']);
        }
        Mail::assertNothingSent();
    }

    /** Deux adresses IPv4 écrites en IPv6 (::ffff:a.b.c.d) ont chacune leur limite. */
    public function test_deux_ipv4_ecrites_en_ipv6_ne_partagent_pas_leur_limite(): void
    {
        foreach (range(1, 5) as $n) {
            $this->withServerVariables(['REMOTE_ADDR' => '::ffff:203.0.113.5'])->postJson('/api/v1/public/contact', $this->message())->assertStatus(202);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '::ffff:198.51.100.7'])->postJson('/api/v1/public/contact', $this->message())->assertStatus(202);
        $this->withServerVariables(['REMOTE_ADDR' => '::ffff:203.0.113.5'])->postJson('/api/v1/public/contact', $this->message())->assertStatus(429);
    }

    /** La limite générale des routes publiques répond en français. */
    public function test_la_limite_des_routes_publiques_repond_en_francais(): void
    {
        foreach (range(1, 60) as $n) {
            $this->postJson('/api/v1/public/contact', $this->message(['captcha' => '']))->assertUnprocessable();
        }

        $this->postJson('/api/v1/public/contact', $this->message())
            ->assertStatus(429)
            ->assertJsonPath('message', 'Trop de requêtes : réessayez dans une minute.');
    }

    /** Une IPv4 écrite en IPv6 sous une autre forme (hexadécimale, zéros écrits) reste cette IPv4. */
    public function test_les_autres_ecritures_d_une_ipv4_en_ipv6_restent_cette_ipv4(): void
    {
        foreach (['::ffff:cb00:7105', '0:0:0:0:0:ffff:203.0.113.5', '::FFFF:203.0.113.5'] as $ecriture) {
            $requete = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ecriture]);

            $this->assertSame('203.0.113.5', CleIp::de($requete), $ecriture);
        }
    }

    /** Une limite plus longue qu'une minute dit sa vraie durée. */
    public function test_le_refus_d_une_limite_dit_sa_vraie_duree(): void
    {
        foreach (range(1, 3) as $n) {
            $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'paul@exemple.ch'])->assertStatus(202);
        }

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'paul@exemple.ch'])
            ->assertStatus(429)->assertJsonPath('message', 'Trop de requêtes : réessayez dans 15 minutes.');
    }

    /** Un message trop court se refuse en français. */
    public function test_un_message_trop_court_se_refuse_en_francais(): void
    {
        $this->postJson('/api/v1/public/contact', $this->message(['message' => 'Bonjour']))
            ->assertUnprocessable()->assertJsonValidationErrors(['message' => 'Écrivez quelques mots de plus.']);
    }

    /** Un nom fait seulement de caractères invisibles (inversion de sens…) est refusé. */
    public function test_un_nom_invisible_est_refuse(): void
    {
        $this->postJson('/api/v1/public/contact', $this->message(['nom' => "\u{202E}"]))
            ->assertUnprocessable()->assertJsonValidationErrors(['nom' => 'Indiquez votre nom.']);
        $this->postJson('/api/v1/public/contact', $this->message(['nom' => "Paul\u{202E} Rochat"]))->assertStatus(202);
        Mail::assertSent(MessageContact::class, fn (MessageContact $mail): bool => ! str_contains($mail->render(), "\u{202E}"));
    }
}
