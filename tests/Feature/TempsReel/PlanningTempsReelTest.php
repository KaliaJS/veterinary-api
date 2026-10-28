<?php

namespace Tests\Feature\TempsReel;

use App\Events\FilesPlanningModifiees;
use App\Events\RendezVousMisAJour;
use App\Events\RendezVousSupprime;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\LignePlanning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Planning en temps réel (règle de Jim, 28.10.2026) : un rendez-vous pris,
 * déplacé, annulé ou modifié, et les files (urgences, demandes en ligne,
 * liste d'attente), sont diffusés sur le canal de la clinique ; les autres
 * postes les voient aussitôt, sans relire.
 */
class PlanningTempsReelTest extends TestCase
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

    private function creer(): int
    {
        return $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => aujourdhui()->addDay()->setTime(10, 0)->toISOString(),
            'duree_minutes' => 30,
            'patient_name' => 'Nala',
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
    }

    public function test_un_rendez_vous_pris_est_diffuse_sur_le_canal_de_la_clinique(): void
    {
        Event::fake([RendezVousMisAJour::class]);

        $id = $this->creer();

        Event::assertDispatched(RendezVousMisAJour::class, fn (RendezVousMisAJour $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id
            && $e->broadcastAs() === 'rendez-vous.mis-a-jour'
            && $e->broadcastWith()['id'] === $id
            && $e->broadcastWith()['patient_name'] === 'Nala');
    }

    public function test_un_rendez_vous_modifie_est_diffuse(): void
    {
        $id = $this->creer();
        Event::fake([RendezVousMisAJour::class]);

        $this->patchJson("/api/v1/rendez-vous/{$id}", ['reason' => 'Boiterie'], $this->enTetes($this->jeton))->assertOk();

        Event::assertDispatched(RendezVousMisAJour::class, fn (RendezVousMisAJour $e): bool => $e->broadcastWith()['reason'] === 'Boiterie');
    }

    public function test_un_rendez_vous_supprime_est_diffuse(): void
    {
        $id = $this->creer();
        Event::fake([RendezVousSupprime::class]);

        $this->deleteJson("/api/v1/rendez-vous/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();

        Event::assertDispatched(RendezVousSupprime::class, fn (RendezVousSupprime $e): bool => $e->broadcastWith() === ['id' => $id]
            && $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id);
    }

    public function test_une_urgence_declaree_change_les_files_de_la_clinique(): void
    {
        Event::fake([FilesPlanningModifiees::class]);

        $this->postJson('/api/v1/urgences', ['patient_name' => 'Rex', 'species' => 'CH', 'owner_name' => 'M. Rochat', 'reason' => 'Plaie', 'triage' => 1, 'duree_minutes' => 30], $this->enTetes($this->jeton))->assertCreated();

        Event::assertDispatched(FilesPlanningModifiees::class, fn (FilesPlanningModifiees $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id);
    }

    public function test_une_demande_en_ligne_change_les_files_de_sa_clinique(): void
    {
        Event::fake([FilesPlanningModifiees::class]);

        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id]);

        Event::assertDispatched(FilesPlanningModifiees::class, fn (FilesPlanningModifiees $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id);
    }
}
