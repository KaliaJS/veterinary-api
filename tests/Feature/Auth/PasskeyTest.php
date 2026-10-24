<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Passkey;
use App\Models\User;
use App\Services\PasskeyAuthService;
use Database\Factories\PasskeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;
use Webauthn\CredentialRecord;

/**
 * Câblage de la cérémonie WebAuthn (la validation cryptographique
 * elle-même relève de web-auth/webauthn-lib) : options + défi en
 * cache à usage unique, refus des défis expirés et des assertions
 * invalides, gestion des passkeys du compte.
 */
class PasskeyTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const array CREDENTIAL_BIDON = [
        'id' => 'abc',
        'rawId' => 'abc',
        'type' => 'public-key',
        'response' => ['clientDataJSON' => 'e30', 'authenticatorData' => 'AA', 'signature' => 'AA'],
    ];

    public function test_les_options_de_connexion_fournissent_un_defi(): void
    {
        User::factory()->create(['email' => 'a.meyer@deuxrives.ch']);

        $reponse = $this->postJson('/api/v1/auth/passkey/options', ['email' => 'a.meyer@deuxrives.ch'])
            ->assertOk()
            ->assertJsonStructure(['defi_id', 'options' => ['challenge', 'rpId']]);

        $this->assertTrue(Cache::has('passkey-authentication-options-'.$reponse->json('defi_id')));
    }

    public function test_les_options_fonctionnent_aussi_sans_email(): void
    {
        $this->postJson('/api/v1/auth/passkey/options')
            ->assertOk()
            ->assertJsonStructure(['defi_id', 'options' => ['challenge']]);
    }

    public function test_un_defi_inconnu_est_refuse(): void
    {
        $this->postJson('/api/v1/auth/passkey/verifier', [
            'defi_id' => '01JDEFIINCONNU0000000000',
            'credential' => self::CREDENTIAL_BIDON,
        ])->assertUnprocessable()->assertJsonValidationErrors(['defi_id' => 'Défi expiré : recommencez la connexion.']);
    }

    public function test_une_assertion_invalide_est_refusee_et_brule_le_defi(): void
    {
        $defiId = $this->postJson('/api/v1/auth/passkey/options')->json('defi_id');

        $this->postJson('/api/v1/auth/passkey/verifier', [
            'defi_id' => $defiId,
            'credential' => self::CREDENTIAL_BIDON,
        ])->assertUnprocessable()->assertJsonValidationErrors('credential');

        $this->assertFalse(Cache::has('passkey-authentication-options-'.$defiId));
    }

    public function test_le_rp_et_les_origines_viennent_de_la_configuration_du_front(): void
    {
        config([
            'passkey.rp_id' => 'veterinary-web.test',
            'passkey.allowed_origins' => ['https://veterinary-web.test'],
        ]);

        $this->postJson('/api/v1/auth/passkey/options')
            ->assertOk()
            ->assertJsonPath('options.rpId', 'veterinary-web.test');

        ['jeton' => $jeton] = $this->employeAvecJeton();

        $this->postJson('/api/v1/auth/passkeys/options', [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('options.rp.id', 'veterinary-web.test');
    }

    public function test_la_configuration_par_defaut_derive_le_rp_de_l_url_du_front(): void
    {
        $config = static function (string $frontUrl): array {
            $precedent = $_SERVER['FRONTEND_URL'] ?? null;
            $_SERVER['FRONTEND_URL'] = $_ENV['FRONTEND_URL'] = $frontUrl;

            try {
                return require base_path('config/passkey.php');
            } finally {
                if ($precedent === null) {
                    unset($_SERVER['FRONTEND_URL'], $_ENV['FRONTEND_URL']);
                } else {
                    $_SERVER['FRONTEND_URL'] = $_ENV['FRONTEND_URL'] = $precedent;
                }
            }
        };

        $vite = $config('http://localhost:5173');
        $this->assertSame('localhost', $vite['rp_id']);
        $this->assertSame(['http://localhost:5173'], $vite['allowed_origins']);

        $herd = $config('https://veterinary-web.test');
        $this->assertSame('veterinary-web.test', $herd['rp_id']);
        $this->assertSame(['https://veterinary-web.test'], $herd['allowed_origins']);
    }

    /**
     * Ordre de la connexion passkey : assertion contrôlée, rattachement
     * résolu, défi consommé en dernier — un compte multi-clinique reçoit
     * la liste des cliniques sans consommer son défi, puis rejoue la
     * même assertion avec `clinique_id`.
     */
    public function test_un_rattachement_multiple_consomme_le_defi_et_donne_un_choix(): void
    {
        $user = User::factory()->create();
        $employeA = Employe::factory()->create(['user_id' => $user->id]);
        $cliniqueB = Clinique::factory()->create();
        $employeB = Employe::factory()->dans($cliniqueB)->create(['user_id' => $user->id]);

        $passkey = Passkey::make(['user_id' => $user->id, 'nom' => 'Clé', 'credential_id' => 'abc']);
        $passkey->setRelation('user', $user);
        $credentialRecord = Mockery::mock(CredentialRecord::class);

        $service = $this->mock(PasskeyAuthService::class);
        $service->shouldReceive('controler')->once()->with(Mockery::type('string'), 'defi')
            ->andReturn(['passkey' => $passkey, 'credentialRecord' => $credentialRecord]);
        // Le défi est consommé dès la liste (il ne se rejoue plus)
        $service->shouldReceive('consommer')->once()->with('defi', $passkey, $credentialRecord);

        $choix = $this->postJson('/api/v1/auth/passkey/verifier', [
            'defi_id' => 'defi',
            'credential' => self::CREDENTIAL_BIDON,
        ])
            ->assertOk()
            ->assertJsonPath('data.choix_clinique', true)
            ->assertJsonPath('data.cliniques.0.id', $employeA->cliniques->sole()->id)
            ->assertJsonPath('data.cliniques.1.id', $cliniqueB->id)
            ->assertJsonMissingPath('data.token')
            ->json('data.choix');

        $this->postJson('/api/v1/auth/choix-clinique', ['choix' => $choix, 'clinique_id' => $cliniqueB->id])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']])
            ->assertJsonPath('data.clinique.id', $cliniqueB->id)
            ->assertJsonPath('data.employe.id', $employeB->id);
    }

    public function test_un_compte_sans_rattachement_recoit_une_session_sans_clinique(): void
    {
        $user = User::factory()->create();
        $passkey = Passkey::make(['user_id' => $user->id, 'nom' => 'Clé', 'credential_id' => 'abc']);
        $passkey->setRelation('user', $user);
        $credentialRecord = Mockery::mock(CredentialRecord::class);

        $service = $this->mock(PasskeyAuthService::class);
        $service->shouldReceive('controler')->once()
            ->andReturn(['passkey' => $passkey, 'credentialRecord' => $credentialRecord]);
        $service->shouldReceive('consommer')->once()->with('defi', $passkey, $credentialRecord);

        $this->postJson('/api/v1/auth/passkey/verifier', [
            'defi_id' => 'defi',
            'credential' => self::CREDENTIAL_BIDON,
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']])
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.employe', null)
            ->assertJsonPath('data.clinique', null)
            ->assertJsonPath('data.permissions', []);
    }

    /* ---------- Pas d'inscription par passkey (24.10.2026) ---------- */

    /** Un compte naît du code e-mail, qui prouve l'adresse ; jamais d'une passkey. */
    public function test_on_ne_s_inscrit_plus_par_passkey(): void
    {
        $this->postJson('/api/v1/auth/inscription/passkey/options', ['email' => 'nouveau@exemple.ch'])->assertNotFound();
        $this->postJson('/api/v1/auth/inscription/passkey', [
            'email' => 'nouveau@exemple.ch',
            'defi_id' => 'defi',
            'credential' => self::CREDENTIAL_BIDON,
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'nouveau@exemple.ch']);
    }

    public function test_la_session_dit_si_le_compte_a_une_passkey(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertJsonPath('data.user.a_une_passkey', false);

        PasskeyFactory::new()->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertJsonPath('data.user.a_une_passkey', true);
    }

    /* ---------- Ajout d'une passkey, connecté ---------- */

    /** Le défi d'un compte ne sert pas à poser une passkey sur un autre. */
    public function test_un_defi_d_enregistrement_ne_sert_qu_au_compte_qui_l_a_demande(): void
    {
        ['jeton' => $jetonA] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonB] = $this->proprietaireAvecJeton();

        $defiId = $this->postJson('/api/v1/auth/passkeys/options', [], $this->enTetes($jetonA))->json('defi_id');

        $this->postJson('/api/v1/auth/passkeys', [
            'nom' => 'Passkey',
            'defi_id' => $defiId,
            'credential' => self::CREDENTIAL_BIDON,
        ], $this->enTetes($jetonB))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['defi_id' => 'Défi émis pour un autre compte, recommencez l’enrôlement.']);

        $this->assertDatabaseCount('passkeys', 0);
    }

    public function test_les_options_d_enregistrement_exigent_une_session(): void
    {
        $this->postJson('/api/v1/auth/passkeys/options')->assertUnauthorized();

        ['jeton' => $jeton] = $this->employeAvecJeton();

        $this->postJson('/api/v1/auth/passkeys/options', [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonStructure(['defi_id', 'options' => ['challenge', 'rp', 'user', 'pubKeyCredParams']]);
    }

    public function test_un_enregistrement_au_defi_expire_est_refuse(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();

        $defiId = $this->postJson('/api/v1/auth/passkeys/options', [], $this->enTetes($jeton))->json('defi_id');

        Cache::forget('passkey-registration-options-'.$defiId);

        $this->postJson('/api/v1/auth/passkeys', [
            'nom' => 'Mon MacBook',
            'defi_id' => $defiId,
            'credential' => self::CREDENTIAL_BIDON,
        ], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('defi_id');
    }

    public function test_la_liste_des_passkeys_du_compte_est_vide_au_depart(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();

        $this->getJson('/api/v1/auth/passkeys', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
