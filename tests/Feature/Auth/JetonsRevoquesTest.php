<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Jetons (23.10.2026) : révoqués à la suspension, au licenciement avec
 * libération et au retrait d'une clinique ; une fin de contrat passée
 * ferme l'accès ; une session dure trente jours au plus ; supprimer une
 * passkey ferme les autres sessions ; se déconnecter reste possible.
 */
class JetonsRevoquesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jetonAdministrateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jetonAdministrateur] = $this->administrateurAvecJeton($this->clinique);
    }

    public function test_la_suspension_supprime_les_jetons_de_la_personne(): void
    {
        ['employe' => $employe, 'user' => $user] = $this->employeAvecJeton($this->clinique);

        $this->postJson("/api/v1/equipe/employes/{$employe->id}/suspendre", [], $this->enTetes($this->jetonAdministrateur))->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_une_fin_de_contrat_passee_ferme_l_acces_et_la_nuit_suspend_et_revoque(): void
    {
        ['employe' => $employe, 'user' => $user, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['fin_contrat_le' => today()->subDay()]);

        $this->getJson('/api/v1/patients', $this->enTetes($jeton))->assertForbidden();
        // Se déconnecter reste possible
        $this->postJson('/api/v1/auth/deconnexion', [], $this->enTetes($jeton))->assertSuccessful();

        ['employe' => $autre, 'user' => $compteAutre] = $this->employeAvecJeton($this->clinique, ['fin_contrat_le' => today()->subDay()]);
        $this->assertSame(1, $compteAutre->tokens()->count());
        $this->artisan('equipe:fermer-contrats')->assertSuccessful();
        $this->assertTrue($autre->refresh()->acces_suspendu);
        $this->assertSame(0, $compteAutre->tokens()->count());
    }

    public function test_une_session_ne_se_prolonge_pas_au_dela_de_trente_jours(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DB::table('personal_access_tokens')->update(['session_depuis' => now()->subDays(31)]);

        $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))->assertUnauthorized();
    }

    public function test_un_rafraichissement_garde_le_debut_de_la_session(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton($this->clinique);
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->update(['session_depuis' => now()->subDays(10)->startOfSecond()]);

        $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))->assertOk();

        $this->assertTrue($user->tokens()->sole()->session_depuis->equalTo(now()->subDays(10)->startOfSecond()));
    }
}
