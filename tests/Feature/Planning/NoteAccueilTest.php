<?php

namespace Tests\Feature\Planning;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Note de l'accueil (décision de Jim, 28.10.2026) : une note libre posée à
 * la prise du rendez-vous (« arrive en retard, chien craintif »), modifiable
 * depuis la fiche, interne à la clinique.
 */
class NoteAccueilTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function creer(array $plus = []): TestResponse
    {
        return $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => aujourdhui()->addDay()->setTime(10, 0)->toISOString(),
            'duree_minutes' => 30,
            'patient_name' => 'Nala',
            ...$plus,
        ], $this->enTetes($this->jeton));
    }

    public function test_la_note_se_pose_a_la_prise_du_rendez_vous(): void
    {
        $reponse = $this->creer(['note_accueil' => 'Arrive en retard, chien craintif'])
            ->assertCreated()
            ->assertJsonPath('data.note_accueil', 'Arrive en retard, chien craintif');

        $this->assertSame('Arrive en retard, chien craintif', RendezVous::withoutGlobalScopes()->findOrFail($reponse->json('data.id'))->note_accueil);
    }

    public function test_sans_note_le_rendez_vous_n_en_a_pas(): void
    {
        $this->creer()->assertCreated()->assertJsonPath('data.note_accueil', null);
    }

    public function test_la_note_se_modifie_et_s_efface_depuis_la_fiche(): void
    {
        $id = $this->creer(['note_accueil' => 'Chien craintif'])->json('data.id');

        $this->patchJson("/api/v1/rendez-vous/{$id}", ['note_accueil' => 'Chien craintif, muselière'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.note_accueil', 'Chien craintif, muselière');
        $this->assertDatabaseHas('rendez_vous_audits', ['rendez_vous_id' => $id, 'champ' => 'noteAccueil']);

        $this->patchJson("/api/v1/rendez-vous/{$id}", ['note_accueil' => null], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.note_accueil', null);
    }

    public function test_une_note_trop_longue_est_refusee_en_francais(): void
    {
        $this->creer(['note_accueil' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note_accueil' => 'La note de l’accueil tient en 1000 caractères au plus.']);
    }

    public function test_la_note_reste_interne_le_proprietaire_ne_la_voit_pas(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id]);
        RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(20), 'note_accueil' => 'Client difficile']);

        $reponse = $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))->assertOk();

        $this->assertStringNotContainsString('Client difficile', $reponse->getContent());
        $this->assertStringNotContainsString('note_accueil', $reponse->getContent());
    }
}
