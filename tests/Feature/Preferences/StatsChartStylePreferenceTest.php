<?php

namespace Tests\Feature\Preferences;

use App\Auth\EmetteurJetons;
use App\Models\Clinique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Statistiques de la Caisse (demande de Jim, 06.11.2026) : le choix entre
 * les barres et la courbe est gardé pour la personne, sur le serveur, et
 * revient sur un autre appareil. Il ne touche pas aux autres préférences.
 */
class StatsChartStylePreferenceTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private User $user;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['user' => $this->user, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_le_choix_barres_ou_courbe_est_garde_pour_la_personne(): void
    {
        $this->patchJson('/api/v1/preferences', ['dental_chart_view' => 'C'], $this->enTetes($this->jeton))->assertOk();

        $this->patchJson('/api/v1/preferences', ['stats_chart_style' => 'line'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.stats_chart_style', 'line')
            ->assertJsonPath('data.dental_chart_view', 'C');

        $iphone = app(EmetteurJetons::class)->emettre($this->user, $this->clinique->id, 'iPhone')['jeton']->plainTextToken;
        $this->getJson('/api/v1/preferences', $this->enTetes($iphone))
            ->assertOk()
            ->assertJsonPath('data.stats_chart_style', 'line');

        ['jeton' => $collegue] = $this->employeAvecJeton($this->clinique);
        $this->getJson('/api/v1/preferences', $this->enTetes($collegue))
            ->assertOk()
            ->assertJsonMissingPath('data.stats_chart_style');
    }

    public function test_seuls_les_barres_et_la_courbe_sont_acceptees(): void
    {
        foreach (['bars', 'line'] as $style) {
            $this->patchJson('/api/v1/preferences', ['stats_chart_style' => $style], $this->enTetes($this->jeton))
                ->assertOk()
                ->assertJsonPath('data.stats_chart_style', $style);
        }
        $this->patchJson('/api/v1/preferences', ['stats_chart_style' => 'pie'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stats_chart_style');
    }
}
