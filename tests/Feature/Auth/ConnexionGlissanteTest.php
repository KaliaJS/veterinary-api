<?php

namespace Tests\Feature\Auth;

use App\Models\JetonApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Connexion « tant qu'on s'en sert » (décision de Jim, 27.10.2026) : une
 * connexion reste valable tant qu'on s'en sert au moins une fois tous les
 * 30 jours, sans limite totale. Le serveur la prolonge à l'usage (au plus
 * une écriture par jour) : l'iPhone, le site et la montre n'ont rien à
 * échanger. Trente jours sans s'en servir : il faut se reconnecter.
 */
class ConnexionGlissanteTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private function jeton(): JetonApi
    {
        return JetonApi::query()->sole();
    }

    public function test_un_jeton_neuf_vaut_trente_jours(): void
    {
        $this->employeAvecJeton();

        $this->assertTrue($this->jeton()->expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
    }

    /** Connecté il y a 25 jours, jamais revenu depuis : un usage ce matin repart pour 30 jours. */
    public function test_chaque_utilisation_prolonge_la_connexion_de_trente_jours(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        DB::table('personal_access_tokens')->update(['created_at' => now()->subDays(25), 'expires_at' => now()->addDays(5)]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.expire_le', fn (string $le): bool => now()->addDays(30)->subMinute()->lt($le));

        $this->assertTrue($this->jeton()->expires_at->gt(now()->addDays(30)->subMinute()));
    }

    /** Connecté depuis plus d'un an et servi chaque mois : toujours valable, au profil, au renouvellement et pour la montre. */
    public function test_une_connexion_utilisee_n_a_pas_de_limite_totale(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        DB::table('personal_access_tokens')->update(['created_at' => now()->subDays(400), 'session_depuis' => now()->subDays(400), 'expires_at' => now()->addDays(10)]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();
        $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))->assertOk();
        $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))->assertOk();
    }

    /** Trente jours sans s'en servir : la connexion est finie. */
    public function test_trente_jours_sans_utilisation_deconnectent(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        DB::table('personal_access_tokens')->update(['created_at' => now()->subDays(31), 'expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertUnauthorized();
    }

    /** Prolongée hier soir : un usage ce matin n'écrit rien (une écriture par jour au plus). */
    public function test_la_prolongation_s_ecrit_une_fois_par_jour_au_plus(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        $hier = now()->addDays(30)->subHours(10)->startOfSecond();
        DB::table('personal_access_tokens')->update(['expires_at' => $hier]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();

        $this->assertTrue($this->jeton()->expires_at->equalTo($hier));
    }

    /** La montre s'en sert aussi : sa connexion se prolonge de la même façon. */
    public function test_la_connexion_de_la_montre_se_prolonge_a_l_usage(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton();
        $montre = $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))->assertOk()->json('data.token');
        JetonApi::query()->whereMorphedTo('tokenable', $user)->where('name', 'montre')->update(['expires_at' => now()->addDays(2)]);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($montre))->assertOk();

        $this->assertTrue(JetonApi::query()->whereMorphedTo('tokenable', $user)->where('name', 'montre')->sole()->expires_at->gt(now()->addDays(30)->subMinute()));
    }
}
