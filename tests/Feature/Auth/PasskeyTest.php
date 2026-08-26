<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Câblage de la cérémonie WebAuthn (la validation cryptographique
 * elle-même est couverte par les tests du package laravel/passkeys) :
 * options + défi en cache, refus des défis expirés et des assertions
 * invalides, gestion des passkeys du compte.
 */
class PasskeyTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_les_options_de_connexion_fournissent_un_defi(): void
    {
        User::factory()->create(['email' => 'a.meyer@deuxrives.ch']);

        $this->postJson('/api/v1/auth/passkey/options', ['email' => 'a.meyer@deuxrives.ch'])
            ->assertOk()
            ->assertJsonStructure(['defi_id', 'options' => ['challenge', 'rpId']]);
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
            'credential' => [
                'id' => 'abc',
                'rawId' => 'abc',
                'type' => 'public-key',
                'response' => ['clientDataJSON' => 'e30', 'authenticatorData' => 'AA', 'signature' => 'AA'],
            ],
        ])->assertUnprocessable();
    }

    public function test_une_assertion_invalide_est_refusee(): void
    {
        $defiId = $this->postJson('/api/v1/auth/passkey/options')->json('defi_id');

        $this->postJson('/api/v1/auth/passkey/verifier', [
            'defi_id' => $defiId,
            'credential' => [
                'id' => 'abc',
                'rawId' => 'abc',
                'type' => 'public-key',
                'response' => ['clientDataJSON' => 'e30', 'authenticatorData' => 'AA', 'signature' => 'AA'],
            ],
        ])->assertUnprocessable();
    }

    public function test_les_options_d_enregistrement_exigent_une_session(): void
    {
        $this->postJson('/api/v1/auth/passkeys/options')->assertUnauthorized();

        ['jeton' => $jeton] = $this->employeAvecJeton();

        $this->postJson('/api/v1/auth/passkeys/options', [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonStructure(['defi_id', 'options' => ['challenge', 'rp', 'user']]);
    }

    public function test_la_liste_des_passkeys_du_compte_est_vide_au_depart(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();

        $this->getJson('/api/v1/auth/passkeys', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
