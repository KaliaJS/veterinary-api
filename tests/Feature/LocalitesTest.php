<?php

namespace Tests\Feature;

use App\Models\Clinique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le NPA remplit la localité (case de notes/todo.md du web, 03.11.2026) :
 * le répertoire officiel des localités de swisstopo, hors ligne
 * (resources/data/localites.json), servi à l'équipe des cliniques.
 */
class LocalitesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_un_npa_donne_sa_localite_et_son_canton(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton(Clinique::factory()->create());

        $this->getJson('/api/v1/localites?npa=1020', $this->enTetes($jeton))
            ->assertOk()
            ->assertExactJson(['data' => [['npa' => '1020', 'localite' => 'Renens VD', 'canton' => 'VD']]]);
    }

    public function test_un_npa_de_plusieurs_localites_les_rend_toutes_la_principale_d_abord(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton(Clinique::factory()->create());

        $this->getJson('/api/v1/localites?npa=3963', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.*.localite', ['Crans-Montana', 'Montana', 'Aminona', 'Crans VS']);
    }

    public function test_un_npa_inconnu_ne_rend_rien_et_un_npa_mal_forme_est_refuse(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton(Clinique::factory()->create());

        $this->getJson('/api/v1/localites?npa=0000', $this->enTetes($jeton))->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/localites?npa=10', $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('npa');
    }

    public function test_reserve_a_l_equipe_des_cliniques(): void
    {
        $this->getJson('/api/v1/localites?npa=1020')->assertUnauthorized();

        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->assertContains($this->getJson('/api/v1/localites?npa=1020', $this->enTetes($jeton))->status(), [403]);
    }
}
