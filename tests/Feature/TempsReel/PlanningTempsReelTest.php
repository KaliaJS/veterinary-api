<?php

namespace Tests\Feature\TempsReel;

use App\Events\FilesPlanningModifiees;
use App\Events\RendezVousMisAJour;
use App\Events\RendezVousSupprime;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\LignePlanning;
use App\Models\Role;
use Carbon\CarbonImmutable;
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

        Event::assertDispatched(RendezVousMisAJour::class, fn (RendezVousMisAJour $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id.'.planning'
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
            && $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id.'.planning');
    }

    public function test_une_urgence_declaree_change_les_files_de_la_clinique(): void
    {
        Event::fake([FilesPlanningModifiees::class]);

        $this->postJson('/api/v1/urgences', ['patient_name' => 'Rex', 'species' => 'CH', 'owner_name' => 'M. Rochat', 'reason' => 'Plaie', 'triage' => 1, 'duree_minutes' => 30], $this->enTetes($this->jeton))->assertCreated();

        Event::assertDispatched(FilesPlanningModifiees::class, fn (FilesPlanningModifiees $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id.'.planning');
    }

    public function test_une_demande_en_ligne_change_les_files_de_sa_clinique(): void
    {
        Event::fake([FilesPlanningModifiees::class]);

        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id]);

        Event::assertDispatched(FilesPlanningModifiees::class, fn (FilesPlanningModifiees $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id.'.planning');
    }

    /** Les fiches de rendez-vous (propriétaire, note de l'accueil) ne vont qu'à qui a le droit Planning. */
    public function test_le_canal_du_planning_demande_le_droit_planning(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-essai',
            'broadcasting.connections.reverb.secret' => 'secret-essai',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');
        ['employe' => $employe, 'jeton' => $sansPlanning] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScope('organisation')->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Stock seulement', 'domaines' => ['stocks']]);
        $employe->update(['role_id' => $role->id]);
        $canal = ['socket_id' => '1234.5678', 'channel_name' => 'private-clinique.'.$this->clinique->id.'.planning'];

        $this->postJson('/api/v1/broadcasting/auth', $canal, $this->enTetes($this->jeton))->assertOk();
        $this->postJson('/api/v1/broadcasting/auth', $canal, $this->enTetes($sansPlanning))->assertForbidden();
    }

    /** Deuxième passe (28.10.2026) : un rendez-vous déplacé est diffusé à sa nouvelle heure. */
    public function test_un_rendez_vous_deplace_est_diffuse(): void
    {
        $id = $this->creer();
        Event::fake([RendezVousMisAJour::class]);
        $nouveau = aujourdhui()->addDay()->setTime(14, 0);

        $this->postJson("/api/v1/rendez-vous/{$id}/deplacer", ['starts_at' => $nouveau->toISOString()], $this->enTetes($this->jeton))->assertOk();

        Event::assertDispatched(RendezVousMisAJour::class, fn (RendezVousMisAJour $e): bool => $e->broadcastWith()['id'] === $id
            && CarbonImmutable::parse($e->broadcastWith()['starts_at'])->equalTo($nouveau));
    }

    /** Deuxième passe (28.10.2026) : un rendez-vous annulé est diffusé annulé. */
    public function test_un_rendez_vous_annule_est_diffuse(): void
    {
        $id = $this->creer();
        Event::fake([RendezVousMisAJour::class]);

        $this->postJson("/api/v1/rendez-vous/{$id}/annuler", ['motif' => 'Empêchement'], $this->enTetes($this->jeton))->assertOk();

        Event::assertDispatched(RendezVousMisAJour::class, fn (RendezVousMisAJour $e): bool => $e->broadcastWith()['id'] === $id && $e->broadcastWith()['status'] === 'annule');
    }

    /** Les messages entre navigateurs (« whisper ») sont refusés : tout passe par l'API. */
    public function test_reverb_refuse_les_messages_entre_clients(): void
    {
        $this->assertSame('none', config('reverb.apps.apps.0.accept_client_events_from'));
    }
}
