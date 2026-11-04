<?php

namespace Tests\Feature\Planning;

use App\Models\Clinique;
use App\Models\HoraireOuverture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Horaires, coupure de midi et jours fermés : des réglages de clinique,
 * jamais des constantes — créés aux défauts à la première lecture,
 * invariants vérifiés à la modification. Jour par jour depuis le
 * 04.11.2026 (OpeningHoursPerDayTest pour les plages).
 */
class HoraireOuvertureTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_la_premiere_lecture_cree_les_horaires_par_defaut(): void
    {
        $this->getJson('/api/v1/horaires', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.days.0.ranges.0.start', '08:00')
            ->assertJsonPath('data.days.0.ranges.1.end', '19:30')
            ->assertJsonPath('data.days.0.ranges.0.end', '12:30')
            ->assertJsonPath('data.days.6.is_open', false);

        $this->assertDatabaseHas('horaires_ouverture', ['clinique_id' => $this->clinique->id]);
    }

    public function test_la_modification_persiste_les_reglages(): void
    {
        $this->putJson('/api/v1/horaires', [
            'days' => HoraireOuverture::weekLike(['ouverture' => '07:30', 'fermeture' => '18:00', 'fermeture_samedi' => '13:00', 'dimanche_ferme' => false, 'pause_active' => false]),
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.days.0.ranges', [['start' => '07:30', 'end' => '18:00']])
            ->assertJsonPath('data.days.5.ranges.0.end', '13:00')
            ->assertJsonPath('data.days.6.is_open', true);
    }

    public function test_un_horaire_hors_du_pas_de_15_minutes_est_refuse(): void
    {
        $this->putJson('/api/v1/horaires', [
            'days' => HoraireOuverture::weekLike(['ouverture' => '08:10', 'pause_active' => false]),
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days.0.ranges.0.start');
    }

    public function test_une_plage_trop_courte_est_refusee(): void
    {
        $this->putJson('/api/v1/horaires', [
            'days' => HoraireOuverture::weekLike(['ouverture' => '08:00', 'fermeture' => '08:15', 'pause_active' => false]),
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days.0.ranges.0.end');
    }

    public function test_une_coupure_qui_chevauche_le_matin_est_refusee(): void
    {
        $jours = HoraireOuverture::weekLike([]);
        $jours[0]['ranges'][1]['start'] = '12:00';

        $this->putJson('/api/v1/horaires', ['days' => $jours], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days.0.ranges.1.start');
    }

    public function test_les_sept_jours_sont_obligatoires(): void
    {
        $this->putJson('/api/v1/horaires', [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days');
    }
}
