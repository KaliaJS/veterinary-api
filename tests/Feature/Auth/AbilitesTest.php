<?php

namespace Tests\Feature\Auth;

use App\Auth\Abilites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les abilities du jeton reflètent le rôle dans la clinique.
 * Contrainte connue (docs/data-model.md) : une assistante ne voit pas
 * la facturation.
 */
class AbilitesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Route sonde du domaine facturation — le vrai groupe de
        // routes est encore vide.
        Route::middleware(['api', 'auth:sanctum', 'clinique', 'abilities:'.Abilites::FACTURATION])
            ->get('/api/v1/facturation/sonde', fn () => response()->json(['ok' => true]));
    }

    public function test_un_veterinaire_a_toutes_les_abilities(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton(attributs: ['role' => 'Vétérinaire']);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.abilities', Abilites::toutes());

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jeton))->assertOk();
    }

    public function test_une_assistante_ne_voit_pas_la_facturation(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton(attributs: ['role' => 'ASV · Accueil']);

        $abilities = $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->json('data.abilities');

        $this->assertNotContains(Abilites::FACTURATION, $abilities);
        $this->assertContains(Abilites::PLANNING, $abilities);

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_le_rafraichissement_recalcule_les_abilities(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton(attributs: ['role' => 'Vétérinaire']);

        $employe->update(['role' => 'ASV']);

        $nouveau = $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($jeton))
            ->assertOk()
            ->json('data.token');

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($nouveau))->assertForbidden();
    }
}
