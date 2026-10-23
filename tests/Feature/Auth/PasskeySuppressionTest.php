<?php

namespace Tests\Feature\Auth;

use App\Auth\EmetteurJetons;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Retrait d'une passkey du compte (appareil perdu) : elle disparaît, les
 * autres sessions du compte se ferment, celle d'où l'on agit reste. La
 * passkey d'un autre compte reste introuvable et ne ferme rien.
 */
class PasskeySuppressionTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    /** Passkey enregistrée (la cryptographie relève de la lib : données brutes) */
    private function passkey(User $user, string $nom): int
    {
        return DB::table('passkeys')->insertGetId([
            'user_id' => $user->id,
            'nom' => $nom,
            'credential_id' => 'cred-'.$user->id.'-'.$nom,
            'donnees' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_retirer_sa_passkey_ferme_les_autres_sessions_et_garde_la_sienne(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->employeAvecJeton();
        $perdue = $this->passkey($user, 'iPhone perdu');
        $gardee = $this->passkey($user, 'MacBook');
        $autreSession = app(EmetteurJetons::class)->emettre($user)['jeton']->plainTextToken;

        $this->deleteJson("/api/v1/auth/passkeys/{$perdue}", [], $this->enTetes($jeton))->assertNoContent();

        $this->assertNull(Passkey::query()->find($perdue));
        $this->getJson('/api/v1/auth/passkeys', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gardee)
            ->assertJsonPath('data.0.nom', 'MacBook');
        $this->getJson('/api/v1/auth/moi', $this->enTetes($autreSession))->assertUnauthorized();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_un_compte_sans_clinique_retire_aussi_sa_passkey(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $passkey = $this->passkey($user, 'iPad');

        $this->deleteJson("/api/v1/auth/passkeys/{$passkey}", [], $this->enTetes($jeton))->assertNoContent();

        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_la_passkey_d_un_autre_compte_est_introuvable_et_ne_ferme_rien(): void
    {
        ['user' => $autre] = $this->proprietaireAvecJeton();
        $sienne = $this->passkey($autre, 'Téléphone de l’autre');
        ['user' => $user, 'jeton' => $jeton] = $this->employeAvecJeton();
        $autreSession = app(EmetteurJetons::class)->emettre($user)['jeton']->plainTextToken;

        $this->deleteJson("/api/v1/auth/passkeys/{$sienne}", [], $this->enTetes($jeton))->assertNotFound();
        $this->deleteJson('/api/v1/auth/passkeys/999999', [], $this->enTetes($jeton))->assertNotFound();

        $this->assertNotNull(Passkey::query()->find($sienne));
        $this->getJson('/api/v1/auth/moi', $this->enTetes($autreSession))->assertOk();
        $this->assertSame(1, $autre->tokens()->count());
    }

    public function test_retirer_une_passkey_exige_une_session(): void
    {
        ['user' => $user] = $this->proprietaireAvecJeton();
        $passkey = $this->passkey($user, 'iPad');

        $this->deleteJson("/api/v1/auth/passkeys/{$passkey}")->assertUnauthorized();
        $this->assertNotNull(Passkey::query()->find($passkey));
    }

    public function test_un_identifiant_qui_n_est_pas_un_nombre_est_introuvable(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();

        $this->deleteJson('/api/v1/auth/passkeys/abc', [], $this->enTetes($jeton))->assertNotFound();
    }
}
