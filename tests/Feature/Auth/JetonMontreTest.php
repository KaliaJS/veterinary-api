<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
use App\Models\JetonApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Jeton de l'Apple Watch (26.10.2026) : l'app iPhone le demande pour sa
 * montre, qui le garde dans son trousseau et le renouvelle elle-même.
 * Un jeton à part, sinon le renouvellement de l'un révoquerait l'autre.
 */
class JetonMontreTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_la_montre_recoit_son_propre_jeton_pour_la_meme_clinique(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton($clinique);

        $montre = $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.clinique.id', $clinique->id)
            ->assertJsonStructure(['data' => ['token', 'expire_le', 'user', 'employe', 'permissions']])
            ->json('data.token');

        // Le jeton de l'iPhone reste valable, celui de la montre aussi
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($montre))->assertOk()->assertJsonPath('data.clinique.id', $clinique->id);
        $this->assertSame(1, $user->tokens()->where('name', 'montre')->count());
    }

    public function test_un_compte_n_a_qu_un_jeton_de_montre(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton();

        $premier = $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))->assertOk()->json('data.token');
        $second = $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))->assertOk()->json('data.token');

        $this->getJson('/api/v1/auth/moi', $this->enTetes($premier))->assertUnauthorized();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($second))->assertOk();
        $this->assertSame(1, $user->tokens()->where('name', 'montre')->count());
    }

    public function test_la_montre_renouvelle_son_jeton_sans_toucher_a_celui_de_l_iphone(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton();
        $montre = $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))->json('data.token');

        $neuf = $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($montre))->assertOk()->json('data.token');

        $this->getJson('/api/v1/auth/moi', $this->enTetes($montre))->assertUnauthorized();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($neuf))->assertOk();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();
        $this->assertSame(1, $user->tokens()->where('name', 'montre')->count());
    }

    public function test_un_compte_sans_clinique_recoit_un_jeton_de_montre_sans_clinique(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $montre = $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.clinique', null)
            ->json('data.token');

        $this->getJson('/api/v1/espace/accueil', $this->enTetes($montre))->assertOk();
        $this->getJson('/api/v1/patients', $this->enTetes($montre))->assertForbidden();
    }

    /** Le jeton de montre garde le début de la session de l'iPhone. */
    public function test_le_jeton_de_montre_garde_le_debut_de_la_session(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton();
        $debut = now()->subDays(10)->startOfSecond();
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->update(['session_depuis' => $debut]);

        $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))->assertOk();

        $this->assertTrue(JetonApi::query()->whereMorphedTo('tokenable', $user)->where('name', 'montre')->sole()->debutSession()->equalTo($debut));
    }

    public function test_sans_jeton_pas_de_jeton_de_montre(): void
    {
        $this->postJson('/api/v1/auth/montre')->assertUnauthorized();
    }
}
