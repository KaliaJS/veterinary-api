<?php

namespace Tests\Feature\Planning;

use App\Models\Clinique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Horaires, pause et jours fermés : des réglages de clinique, jamais
 * des constantes — créés aux défauts à la première lecture, invariants
 * du data-model à la modification.
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
            ->assertJsonPath('data.ouverture', '08:00')
            ->assertJsonPath('data.fermeture', '19:30')
            ->assertJsonPath('data.pause_debut', '12:30')
            ->assertJsonPath('data.dimanche_ferme', true);

        $this->assertDatabaseHas('horaires_ouverture', ['clinique_id' => $this->clinique->id]);
    }

    public function test_la_modification_persiste_les_reglages(): void
    {
        $this->putJson('/api/v1/horaires', [
            'ouverture' => '07:30',
            'fermeture' => '18:00',
            'fermeture_samedi' => '13:00',
            'samedi_ferme' => false,
            'dimanche_ferme' => false,
            'pause_active' => false,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.ouverture', '07:30')
            ->assertJsonPath('data.dimanche_ferme', false)
            ->assertJsonPath('data.pause_active', false);
    }

    public function test_un_horaire_hors_du_pas_de_15_minutes_est_refuse(): void
    {
        $this->putJson('/api/v1/horaires', [
            'ouverture' => '08:10',
            'fermeture' => '19:30',
            'pause_active' => false,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ouverture');
    }

    public function test_une_fermeture_a_moins_de_deux_heures_de_l_ouverture_est_refusee(): void
    {
        $this->putJson('/api/v1/horaires', [
            'ouverture' => '08:00',
            'fermeture' => '09:00',
            'pause_active' => false,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fermeture');
    }

    public function test_une_pause_hors_bornes_est_refusee(): void
    {
        $this->putJson('/api/v1/horaires', [
            'ouverture' => '08:00',
            'fermeture' => '19:30',
            'pause_active' => true,
            'pause_debut' => '08:15',
            'pause_fin' => '19:15',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pause_debut', 'pause_fin']);
    }

    public function test_la_pause_est_obligatoire_quand_elle_est_active(): void
    {
        $this->putJson('/api/v1/horaires', [
            'ouverture' => '08:00',
            'fermeture' => '19:30',
            'pause_active' => true,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pause_debut', 'pause_fin']);
    }
}
