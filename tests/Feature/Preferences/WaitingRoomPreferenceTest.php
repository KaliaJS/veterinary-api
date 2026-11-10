<?php

namespace Tests\Feature\Preferences;

use App\Auth\EmetteurJetons;
use App\Models\Clinique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La salle d'attente dans « Rendez-vous » (demande de Jim, 10.11.2026),
 * case S2 : un interrupteur pour chaque personne, gardé sur le serveur,
 * éteint par défaut.
 */
class WaitingRoomPreferenceTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_s2_la_salle_d_attente_est_eteinte_par_defaut_et_gardee_pour_la_personne(): void
    {
        $clinique = Clinique::factory()->create();
        ['user' => $user, 'jeton' => $jeton] = $this->employeAvecJeton($clinique);
        ['jeton' => $collegue] = $this->employeAvecJeton($clinique);

        $this->getJson('/api/v1/preferences', $this->enTetes($jeton))->assertOk()->assertJsonPath('data.waiting_room', false);
        $this->patchJson('/api/v1/preferences', ['waiting_room' => true], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.waiting_room', true);
        $this->patchJson('/api/v1/preferences', ['waiting_room' => 'peut-être'], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('waiting_room');

        // Sur un autre appareil, la même ; un collègue garde la sienne
        $iphone = app(EmetteurJetons::class)->emettre($user, $clinique->id, 'iPhone')['jeton']->plainTextToken;
        $this->getJson('/api/v1/preferences', $this->enTetes($iphone))->assertJsonPath('data.waiting_room', true);
        $this->getJson('/api/v1/preferences', $this->enTetes($collegue))->assertJsonPath('data.waiting_room', false);
    }
}
