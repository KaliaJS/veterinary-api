<?php

namespace Tests\Feature\Auth;

use App\Auth\EmetteurJetons;
use App\Models\Clinique;
use Carbon\CarbonImmutable;
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
        // Jeton émis pendant le contrat, qui se termine ensuite (la connexion ne
        // propose plus une clinique dont le contrat est terminé, 27.10.2026)
        ['employe' => $employe, 'user' => $user, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->forceFill(['fin_contrat_le' => aujourdhui()->subDay()])->save();

        $this->getJson('/api/v1/patients', $this->enTetes($jeton))->assertForbidden();
        // Se déconnecter reste possible
        $this->postJson('/api/v1/auth/deconnexion', [], $this->enTetes($jeton))->assertSuccessful();

        ['employe' => $autre, 'user' => $compteAutre] = $this->employeAvecJeton($this->clinique);
        $autre->forceFill(['fin_contrat_le' => aujourdhui()->subDay()])->save();
        $this->assertSame(1, $compteAutre->tokens()->count());
        $this->artisan('equipe:fermer-contrats')->assertSuccessful();
        $this->assertTrue($autre->refresh()->acces_suspendu);
        $this->assertSame(0, $compteAutre->tokens()->count());
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

    /** Changer de clinique (même la sienne) garde le début de la session. */
    public function test_changer_de_clinique_garde_le_debut_de_la_session(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton($this->clinique);
        // Une session de plus d'un an, servie chaque mois : aucune limite totale (27.10.2026)
        $debut = now()->subDays(400)->startOfSecond();
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->update(['session_depuis' => $debut, 'created_at' => $debut]);

        $neuf = $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $this->clinique->id], $this->enTetes($jeton))->assertOk()->json('data.token');
        $this->assertTrue($user->tokens()->sole()->session_depuis->equalTo($debut));
        $this->assertNotSame($jeton, $neuf);
    }

    /** La nuit, une fin de contrat se compare à la date de la clinique (New York : encore le dernier jour à 00:15 à Zurich). */
    public function test_la_fermeture_de_nuit_suit_la_date_de_la_clinique(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 00:15', 'Europe/Zurich'));
        $newYork = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'fuseau_horaire' => 'America/New_York']);
        ['employe' => $employe] = $this->employeAvecJeton($newYork);
        $employe->forceFill(['fin_contrat_le' => '2026-11-10'])->save();

        // 18:15 le 10 à New York : son dernier jour n'est pas fini
        $this->artisan('equipe:fermer-contrats')->assertSuccessful();
        $this->assertFalse((bool) $employe->refresh()->acces_suspendu);

        // 00:15 le 11 à New York
        $this->travelTo(CarbonImmutable::parse('2026-11-11 06:15', 'Europe/Zurich'));
        $this->artisan('equipe:fermer-contrats')->assertSuccessful();
        $this->assertTrue((bool) $employe->refresh()->acces_suspendu);
    }
}
