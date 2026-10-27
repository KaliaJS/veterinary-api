<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
use App\Models\JetonApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Connexion « tant qu'on s'en sert » (décision de Jim, 27.10.2026) : une
 * connexion reste valable tant qu'on s'en sert au moins une fois tous les
 * 30 jours, sans limite totale. Le serveur la prolonge à chaque usage,
 * dans la même écriture que l'heure de dernier usage notée par Sanctum
 * (Jim, 27.10.2026 : 30 jours pile plutôt qu'une écriture par jour) ;
 * l'iPhone, le site et la montre n'ont rien à échanger. Trente jours sans
 * s'en servir : il faut se reconnecter.
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
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))->assertOk();
    }

    /** Servie ce matin, puis trente jours et une minute sans s'en servir : la connexion est finie. */
    public function test_trente_jours_sans_utilisation_deconnectent(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        $this->travel(10)->hours();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->travel(30 * 24 * 60 + 1)->minutes();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertUnauthorized();
    }

    /** Connecté à 9 h, servi à 19 h : valable jusqu'au 30e jour à 18 h 59, trente jours pile après ce dernier usage. */
    public function test_la_connexion_tient_trente_jours_pile_apres_le_dernier_usage(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        $this->travel(10)->hours();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->travel(30 * 24 * 60 - 1)->minutes();

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();
    }

    /** Prolonger ne coûte rien : une requête n'écrit le jeton qu'une fois (fin et dernier usage ensemble). */
    public function test_une_requete_n_ecrit_le_jeton_qu_une_fois(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        DB::table('personal_access_tokens')->update(['expires_at' => now()->addDays(5)]);
        $ecritures = 0;
        DB::listen(function ($requete) use (&$ecritures): void {
            if (str_starts_with(strtolower($requete->sql), 'update') && str_contains($requete->sql, 'personal_access_tokens')) {
                $ecritures++;
            }
        });

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();

        $this->assertSame(1, $ecritures);
        $this->assertTrue($this->jeton()->expires_at->gt(now()->addDays(30)->subMinute()));
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

    private function vautTrenteJours(?string $quand): bool
    {
        return $quand !== null && now()->addDays(30)->subMinute()->lt($quand) && now()->addDays(30)->addMinute()->gt($quand);
    }

    /** Chaque voie qui émet un jeton lui donne une fin à 30 jours : connexion, propriétaire, montre, renouvellement, changement de clinique. */
    public function test_chaque_voie_d_emission_donne_une_fin_a_trente_jours(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton($clinique);
        ['user' => $proprietaire] = $this->proprietaireAvecJeton();

        $this->assertTrue($this->vautTrenteJours($proprietaire->tokens()->sole()->expires_at?->toIso8601String()));
        $this->postJson('/api/v1/auth/montre', [], $this->enTetes($jeton))->assertOk();
        $this->assertTrue($this->vautTrenteJours(JetonApi::query()->whereMorphedTo('tokenable', $user)->where('name', 'montre')->sole()->expires_at?->toIso8601String()));
        $this->app['auth']->forgetGuards();
        $neuf = $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))->assertOk()->json('data.token');
        $this->assertTrue($this->vautTrenteJours(JetonApi::findToken($neuf)?->expires_at?->toIso8601String()));
        $this->app['auth']->forgetGuards();
        $autre = $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $clinique->id], $this->enTetes($neuf))->assertOk()->json('data.token');
        $this->assertTrue($this->vautTrenteJours(JetonApi::findToken($autre)?->expires_at?->toIso8601String()));
    }

    /** Un jeton sans fin n'est jamais accepté : il ne pourrait jamais expirer. */
    public function test_un_jeton_sans_fin_est_refuse(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        DB::table('personal_access_tokens')->update(['expires_at' => null]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertUnauthorized();
    }

    /** Un propriétaire (jeton sans clinique) se prolonge comme les autres. */
    public function test_un_proprietaire_se_prolonge_aussi(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        DB::table('personal_access_tokens')->update(['expires_at' => now()->addDays(3)]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk();

        $this->assertTrue($this->jeton()->expires_at->gt(now()->addDays(30)->subMinute()));
    }
}
