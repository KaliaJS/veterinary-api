<?php

namespace Tests\Feature\Equipe;

use App\Enums\DomainePermission;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Chacun demande ses congés depuis le planning (Jim, 11.11.2026, É10) :
 * en attente de validation, ou validés tout de suite quand l'organisation
 * l'a permis (« sans validation », désactivé de base). Des rendez-vous
 * pris ces jours-là : la demande attend la validation, jamais annulés
 * par l'employé lui-même.
 */
class DemandesCongeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $moi;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['employe' => $this->moi, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        // Sans le droit Équipe : seulement le planning en lecture et ses demandes
        DerogationPermission::create(['employe_id' => $this->moi->id, 'domaine' => DomainePermission::Equipe, 'est_accordee' => false]);
    }

    private function demander(array $donnees = []): TestResponse
    {
        return $this->postJson('/api/v1/equipe/demandes-conge', ['debut_le' => '2026-12-21', 'fin_le' => '2026-12-24', ...$donnees], $this->enTetes($this->jeton));
    }

    private function sansValidation(): void
    {
        $this->clinique->societe->organisation->forceFill(['conges_sans_validation' => true])->save();
    }

    public function test_la_demande_attend_la_validation(): void
    {
        $this->demander(['note' => 'Noël'])->assertCreated()
            ->assertJsonPath('data.employe_id', $this->moi->id)
            ->assertJsonPath('data.type', 'conge')
            ->assertJsonPath('data.statut', 'att')
            ->assertJsonPath('data.note', 'Noël');
    }

    public function test_sans_validation_le_conge_est_pose_tout_de_suite(): void
    {
        $this->sansValidation();

        $this->demander()->assertCreated()->assertJsonPath('data.statut', 'ok');
        $this->assertSame($this->moi->user_id, Absence::query()->sole()->valide_par_user_id);
    }

    public function test_sans_validation_mais_des_rendez_vous_pris_la_demande_attend(): void
    {
        $this->sansValidation();
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'employe_id' => $this->moi->id]);
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $ligne->id,
            'starts_at' => CarbonImmutable::parse('2026-12-22 09:00', 'Europe/Zurich'), 'ends_at' => CarbonImmutable::parse('2026-12-22 09:30', 'Europe/Zurich'),
        ]);

        $this->demander()->assertCreated()->assertJsonPath('data.statut', 'att')->assertJsonPath('meta.rendez_vous', 1);
        $this->assertSame($rendezVous->status, $rendezVous->fresh()->status);
    }

    public function test_une_demande_passee_ou_qui_chevauche_est_refusee(): void
    {
        // Un samedi et un dimanche : aucun jour de congé à demander
        $this->demander(['debut_le' => '2026-12-19', 'fin_le' => '2026-12-20'])->assertUnprocessable()->assertJsonValidationErrors('debut_le');
        $this->demander(['debut_le' => '2026-11-02', 'fin_le' => '2026-11-03'])->assertUnprocessable()->assertJsonValidationErrors('debut_le');
        $this->demander()->assertCreated();
        $this->demander(['debut_le' => '2026-12-24', 'fin_le' => '2026-12-28'])->assertUnprocessable()->assertJsonValidationErrors('debut_le');
    }

    public function test_on_retire_sa_demande_pas_celle_d_un_autre_ni_un_conge_valide(): void
    {
        $mienne = $this->demander()->json('data.id');
        $collegue = Employe::factory()->dans($this->clinique)->create();
        $autre = Absence::factory()->create(['employe_id' => $collegue->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::EnAttente, 'debut_le' => '2026-12-21', 'fin_le' => '2026-12-21']);
        // Posé par la personne elle-même (un congé validé par un autre ne se retire pas, audit C04)
        $validee = Absence::factory()->create(['employe_id' => $this->moi->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::Validee, 'debut_le' => '2027-01-11', 'fin_le' => '2027-01-15', 'valide_par_user_id' => $this->moi->user_id]);

        $this->deleteJson("/api/v1/equipe/demandes-conge/{$autre->id}", [], $this->enTetes($this->jeton))->assertForbidden();
        $this->deleteJson("/api/v1/equipe/demandes-conge/{$validee->id}", [], $this->enTetes($this->jeton))->assertForbidden();
        $this->deleteJson("/api/v1/equipe/demandes-conge/{$mienne}", [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertNull(Absence::query()->find($mienne));

        // Posé sans validation et pas encore commencé : il se retire aussi
        $this->sansValidation();
        $this->deleteJson("/api/v1/equipe/demandes-conge/{$validee->id}", [], $this->enTetes($this->jeton))->assertNoContent();
    }
}
