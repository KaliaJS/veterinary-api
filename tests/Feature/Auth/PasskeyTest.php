<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

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
        ])->assertUnprocessable();
    }

    public function test_une_assertion_invalide_est_refusee(): void
    {
        $defiId = $this->postJson('/api/v1/auth/passkey/options')->json('defi_id');

        $this->postJson('/api/v1/auth/passkey/verifier', [
            'defi_id' => $defiId,
            'credential' => self::CREDENTIAL_BIDON,
        ])->assertUnprocessable();
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
