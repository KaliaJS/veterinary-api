<?php

namespace Tests\Feature\Preferences;

use App\Auth\EmetteurJetons;
use App\Models\Clinique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Schéma dentaire (demande de Jim, 04.11.2026) : la vue choisie (A fiche
 * AVDC, B carte en couleur, C vue de côté, D quadrants) est gardée pour la
 * personne, sur le serveur, et revient à la prochaine ouverture, sur un
 * autre appareil aussi. Elle ne touche pas aux outils favoris.
 */
class DentalChartViewPreferenceTest extends TestCase
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

    public function test_la_vue_choisie_est_gardee_pour_la_personne_a_la_prochaine_ouverture(): void
    {
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dent']], $this->enTetes($this->jeton))->assertOk();

        $this->patchJson('/api/v1/preferences', ['dental_chart_view' => 'C'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.dental_chart_view', 'C')
            ->assertJsonPath('data.tool_favorites', ['dent']);

        // L'iPhone de la même personne la retrouve
        $iphone = app(EmetteurJetons::class)->emettre($this->user, $this->clinique->id, 'iPhone')['jeton']->plainTextToken;
        $this->getJson('/api/v1/preferences', $this->enTetes($iphone))
            ->assertOk()
            ->assertJsonPath('data.dental_chart_view', 'C');

        // Une collègue garde la sienne (aucune encore)
        ['jeton' => $collegue] = $this->employeAvecJeton($this->clinique);
        $this->getJson('/api/v1/preferences', $this->enTetes($collegue))
            ->assertOk()
            ->assertJsonMissingPath('data.dental_chart_view');
    }

    public function test_seules_les_quatre_vues_sont_acceptees(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $vue) {
            $this->patchJson('/api/v1/preferences', ['dental_chart_view' => $vue], $this->enTetes($this->jeton))
                ->assertOk()
                ->assertJsonPath('data.dental_chart_view', $vue);
        }
        $this->patchJson('/api/v1/preferences', ['dental_chart_view' => 'E'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dental_chart_view');
    }
}
