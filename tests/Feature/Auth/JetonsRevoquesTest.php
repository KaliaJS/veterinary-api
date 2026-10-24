<?php

namespace Tests\Feature\Auth;

use App\Auth\EmetteurJetons;
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

    public function test_un_licenciement_qui_coupe_l_acces_supprime_les_jetons(): void
    {
        ['employe' => $employe, 'user' => $user] = $this->employeAvecJeton($this->clinique);

        $this->postJson("/api/v1/equipe/employes/{$employe->id}/licencier", [
            'fin_contrat_le' => aujourdhui()->addMonth()->toDateString(),
            'revoquer_acces' => true,
        ], $this->enTetes($this->jetonAdministrateur))->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    /** Retiré d'une clinique : ses jetons de cette clinique disparaissent, ceux de l'autre restent. */
    public function test_le_retrait_d_une_clinique_supprime_seulement_ses_jetons(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['employe' => $employe, 'user' => $user] = $this->employeAvecJeton($this->clinique);
        $employe->cliniques()->syncWithoutDetaching([$soeur->id]);
        app(EmetteurJetons::class)->emettre($user, $soeur->id);
        $this->assertSame(2, $user->tokens()->count());

        $this->putJson("/api/v1/equipe/employes/{$employe->id}/cliniques", ['cliniques' => [$this->clinique->id]], $this->enTetes($this->jetonAdministrateur))->assertOk();

        $this->assertSame([$this->clinique->id], $user->tokens()->pluck('clinique_id')->all());
    }

    public function test_une_fin_de_contrat_passee_ferme_l_acces_et_la_nuit_suspend_et_revoque(): void
    {
        ['employe' => $employe, 'user' => $user, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['fin_contrat_le' => aujourdhui()->subDay()]);

        $this->getJson('/api/v1/patients', $this->enTetes($jeton))->assertForbidden();
        // Se déconnecter reste possible
        $this->postJson('/api/v1/auth/deconnexion', [], $this->enTetes($jeton))->assertSuccessful();

        ['employe' => $autre, 'user' => $compteAutre] = $this->employeAvecJeton($this->clinique, ['fin_contrat_le' => aujourdhui()->subDay()]);
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
        // Lu une fois : recalculé après la requête, il glissait d'une seconde
        $debut = now()->subDays(10)->startOfSecond();
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->update(['session_depuis' => $debut]);

        $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))->assertOk();

        $this->assertTrue($user->tokens()->sole()->session_depuis->equalTo($debut));
    }

    /** Changer de clinique (même la sienne) ne remet pas la session à neuf. */
    public function test_changer_de_clinique_ne_prolonge_pas_la_session(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton($this->clinique);
        $debut = now()->subDays(10)->startOfSecond();
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->update(['session_depuis' => $debut]);

        $neuf = $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $this->clinique->id], $this->enTetes($jeton))->assertOk()->json('data.token');
        $this->assertTrue($user->tokens()->sole()->session_depuis->equalTo($debut));

        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->update(['session_depuis' => now()->subDays(31)]);
        $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $this->clinique->id], $this->enTetes($neuf))->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }
}
