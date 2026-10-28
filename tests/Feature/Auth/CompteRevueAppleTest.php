<?php

namespace Tests\Feature\Auth;

use App\Auth\AdressesInvitees;
use App\Auth\CodeEmail;
use App\Auth\CompteRevueApple;
use App\Mail\AccesSurInvitation;
use App\Mail\EssaisCompteRevue;
use App\Models\User;
use App\Notifications\CodeEmailNotification;
use App\Rules\AdresseSansAccent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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

    /** La limite du jour (20 essais par adresse et par IP) vaut aussi pour l'adresse de revue, même avec un code redemandé. */
    public function test_la_limite_d_essais_du_jour_vaut_pour_l_adresse_de_revue(): void
    {
        foreach (range(1, 4) as $tour) {
            $this->travel(16)->minutes();
            $this->demander(self::ADRESSE);
            foreach (range(1, 5) as $essai) {
                $this->postJson('/api/v1/auth/code-email/verifier', ['email' => self::ADRESSE, 'code' => '11111111'])->assertUnprocessable();
            }
        }

        // Vingt essais faux dans la journée : un code neuf ne rouvre pas les essais, même le bon est refusé
        $this->travel(16)->minutes();
        $this->demander(self::ADRESSE);
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => self::ADRESSE, 'code' => self::CODE])
            ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Trop de tentatives. Demandez un nouveau code.']);
        $this->assertFalse(User::query()->where('email', self::ADRESSE)->exists());
    }

    /** Sans code réglé, ou un code qui n'a pas 8 chiffres : l'adresse est traitée comme les autres. */
    public function test_sans_reglage_valable_rien_ne_change(): void
    {
        foreach ([null, '1234', 'abcdefgh', '123456789'] as $code) {
            config(['plateforme.revue_apple.code' => $code]);
            Mail::fake();
            // Trois demandes de code par quart d'heure au plus
            $this->travel(16)->minutes();

            $this->demander(self::ADRESSE);

            // Non invitée : « sur invitation » au lieu d'un code, comme toute adresse
            Mail::assertSent(AccesSurInvitation::class);
        }
    }

    /** Hors accès sur invitation, sans réglage valable : un code tiré au hasard arrive par e-mail, comme pour toute adresse. */
    public function test_sans_reglage_valable_l_adresse_recoit_un_code_par_email(): void
    {
        config(['plateforme.acces_sur_invitation' => false]);

        foreach ([null, '1234', 'abcdefgh', '123456789'] as $code) {
            config(['plateforme.revue_apple.code' => $code]);
            Notification::fake();
            $this->travel(16)->minutes();

            $this->demander(self::ADRESSE);

            Notification::assertSentOnDemand(
                CodeEmailNotification::class,
                fn (CodeEmailNotification $notification, array $canaux, AnonymousNotifiable $destinataire): bool => $destinataire->routes['mail'] === self::ADRESSE
                    && preg_match('/^\d{8}$/', $notification->code) === 1,
            );
        }
    }

    public function test_le_code_fixe_ne_sert_qu_a_l_adresse_de_revue(): void
    {
        config(['plateforme.acces_sur_invitation' => false]);

        // Même domaine, même partie avant le @, adresse qui la contient : aucune n'est l'adresse de revue
        foreach (['paul@exemple.ch', 'autre@veterinary.app', 'revue@exemple.ch', 'revue@veterinary.app.ch', 'xrevue@veterinary.app'] as $email) {
            $this->demander($email);

            $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $email, 'code' => self::CODE])->assertUnprocessable();
            $this->assertFalse(User::query()->where('email', $email)->exists());
        }
    }

    /** Pendant l'accès sur invitation, le compte de revue n'ouvre la porte qu'à sa propre adresse. */
    public function test_seule_l_adresse_de_revue_passe_l_acces_sur_invitation(): void
    {
        $this->demander('paul@exemple.ch');

        Mail::assertSent(AccesSurInvitation::class, fn (AccesSurInvitation $mail) => $mail->hasTo('paul@exemple.ch'));
        Notification::assertNothingSent();
        // Ni une adresse quelconque, ni ses voisines (même domaine, même partie avant le @)
        foreach (['paul@exemple.ch', 'revue@exemple.ch', 'autre@veterinary.app', 'revue@veterinary.app.ch', 'xrevue@veterinary.app'] as $email) {
            $this->assertFalse(app(AdressesInvitees::class)->peutCreerUnCompte($email), $email);
        }
        $this->assertTrue(app(AdressesInvitees::class)->peutCreerUnCompte(self::ADRESSE));
        $this->assertTrue(app(AdressesInvitees::class)->peutCreerUnCompte(' Revue@Veterinary.APP '));
    }

    /** Un caractère non ASCII (signe Kelvin, qui devient « k » en minuscules) n'obtient pas le code fixe. */
    public function test_une_adresse_non_ascii_n_obtient_pas_le_code_fixe(): void
    {
        config(['plateforme.revue_apple.email' => 'kiosque@veterinary.app']);

        $this->assertSame(self::CODE, app(CompteRevueApple::class)->codePour('Kiosque@veterinary.app'));
        $this->assertNull(app(CompteRevueApple::class)->codePour("\u{212A}iosque@veterinary.app"));
    }

    /** Cinq essais faux sur l'adresse de revue : la plateforme est alertée (une fois par jour). */
    public function test_des_essais_faux_sur_l_adresse_de_revue_alertent_la_plateforme(): void
    {
        // En pleine journée : lancé vers minuit, les 32 minutes du test changeraient de jour (une alerte par jour)
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich'));
        config(['plateforme.alerte_email' => 'alerte@exemple.ch']);

        foreach (range(1, 2) as $tour) {
            $this->travel(16)->minutes();
            $this->demander(self::ADRESSE);
            foreach (range(1, 5) as $essai) {
                $this->postJson('/api/v1/auth/code-email/verifier', ['email' => self::ADRESSE, 'code' => '11111111'])->assertUnprocessable();
            }
        }

        Mail::assertSent(EssaisCompteRevue::class, 1);
        Mail::assertSent(EssaisCompteRevue::class, fn (EssaisCompteRevue $mail) => $mail->hasTo('alerte@exemple.ch'));
    }

    /** Une graphie voisine (signe Kelvin) ne se connecte pas avec le code fixe demandé par l'adresse de revue. */
    public function test_une_graphie_voisine_ne_se_connecte_pas_avec_le_code_fixe(): void
    {
        // Accès ouvert : en SQLite, la graphie voisine créerait son compte ; en
        // MySQL (utf8mb4_unicode_ci), elle trouverait celui de revue
        config(['plateforme.revue_apple.email' => 'kiosque@veterinary.app', 'plateforme.acces_sur_invitation' => false]);
        User::factory()->create(['email' => 'kiosque@veterinary.app']);
        $kelvin = "\u{212A}iosque@veterinary.app";

        $this->demander('kiosque@veterinary.app');
        // Refusée à la porte (adresse sans accent ni caractère spécial, 28.10.2026)
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => $kelvin, 'code' => self::CODE])
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => AdresseSansAccent::MESSAGE]);
        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => $kelvin])->assertUnprocessable();
        // Et derrière la porte : jamais le code fixe, jamais rien de rangé à sa place
        app(CodeEmail::class)->envoyer($kelvin);
        try {
            app(CodeEmail::class)->controler($kelvin, self::CODE);
            $this->fail('La graphie voisine a passé le contrôle.');
        } catch (ValidationException) {
        }

        // L'adresse elle-même se connecte toujours
        $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'kiosque@veterinary.app', 'code' => self::CODE])->assertSuccessful();
        Notification::assertNothingSent();
    }

    /** Les codes faux se comptent sur la journée, tous codes confondus : trois tours de quatre essais alertent. */
    public function test_des_tours_de_quatre_essais_faux_alertent_aussi(): void
    {
        config(['plateforme.alerte_email' => 'alerte@exemple.ch']);

        foreach (range(1, 3) as $tour) {
            $this->travel(16)->minutes();
            $this->demander(self::ADRESSE);
            foreach (range(1, 4) as $essai) {
                $this->postJson('/api/v1/auth/code-email/verifier', ['email' => self::ADRESSE, 'code' => '11111111'])->assertUnprocessable();
            }
        }

        Mail::assertSent(EssaisCompteRevue::class, 1);
    }

    /** Cinq codes faux sous une graphie voisine alertent aussi. */
    public function test_des_essais_faux_sous_une_graphie_voisine_alertent(): void
    {
        config(['plateforme.alerte_email' => 'alerte@exemple.ch', 'plateforme.revue_apple.email' => 'kiosque@veterinary.app']);
        $kelvin = "\u{212A}iosque@veterinary.app";

        // Refusée à la porte ; derrière, ses codes faux comptent quand même
        app(CodeEmail::class)->envoyer($kelvin);
        foreach (range(1, 5) as $essai) {
            try {
                app(CodeEmail::class)->controler($kelvin, '11111111');
            } catch (ValidationException) {
            }
        }

        Mail::assertSent(EssaisCompteRevue::class, 1);
    }
}
