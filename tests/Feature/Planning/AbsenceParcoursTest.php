<?php

namespace Tests\Feature\Planning;

use App\Enums\DomainePermission;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\LignePlanning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Parcours nominal d'une absence, de la demande à la suppression : lecture
 * de la fiche, modification du type et de la note, filtre par employé,
 * issue d'un refus ; et ce que la validation refuse (statut imposé à la
 * création, période à l'envers, employé d'une clinique sœur).
 */
class AbsenceParcoursTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private User $user;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);

        ['user' => $this->user, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_une_demande_se_lit_se_modifie_puis_se_supprime(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $debut = aujourdhui()->addWeek();

        $id = $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id,
            'type' => 'conge',
            'debut_le' => $debut->toDateString(),
            'fin_le' => $debut->clone()->addDays(4)->toDateString(),
            'note' => 'Vacances d’automne',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.statut', 'att')
            ->assertJsonPath('data.valide_par_user_id', null)
            ->json('data.id');

        $this->getJson("/api/v1/absences/{$id}", $entetes)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.employe_id', $this->ligne->employe_id)
            ->assertJsonPath('data.type', 'conge')
            ->assertJsonPath('data.debut_le', $debut->toDateString())
            ->assertJsonPath('data.fin_le', $debut->clone()->addDays(4)->toDateString())
            ->assertJsonPath('data.note', 'Vacances d’automne');

        // Type et note se corrigent ; le statut ne change que par valider ou refuser
        $this->patchJson("/api/v1/absences/{$id}", [
            'type' => 'formation',
            'note' => null,
            'statut' => 'ok',
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.type', 'formation')
            ->assertJsonPath('data.note', null)
            ->assertJsonPath('data.statut', 'att');

        $this->deleteJson("/api/v1/absences/{$id}", [], $entetes)->assertNoContent();

        $this->assertDatabaseMissing('absences', ['id' => $id]);
        $this->getJson("/api/v1/absences/{$id}", $entetes)->assertNotFound();
    }

    public function test_la_fiche_se_lit_avec_le_seul_domaine_planning(): void
    {
        $absence = Absence::factory()->enAttente()->create(['employe_id' => $this->ligne->employe_id]);
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Equipe,
            'est_accordee' => false,
        ]);

        $this->getJson("/api/v1/absences/{$absence->id}", $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $absence->id);
    }

    public function test_un_refus_est_definitif_et_garde_qui_l_a_decide(): void
    {
        $absence = Absence::factory()->enAttente()->create(['employe_id' => $this->ligne->employe_id]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/absences/{$absence->id}/refuser", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'ref')
            ->assertJsonPath('data.valide_par_user_id', $this->user->id);

        // Refusée : ni validée ni refusée une seconde fois
        $this->postJson("/api/v1/absences/{$absence->id}/valider", [], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');
        $this->postJson("/api/v1/absences/{$absence->id}/refuser", [], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');

        $this->getJson('/api/v1/absences?statut=ref', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $absence->id);
    }

    public function test_la_liste_se_filtre_par_employe_du_plus_recent_au_plus_ancien(): void
    {
        $collegue = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $ancienne = Absence::factory()->create(['employe_id' => $this->ligne->employe_id, 'debut_le' => aujourdhui()->subMonth(), 'fin_le' => aujourdhui()->subMonth()]);
        $recente = Absence::factory()->create(['employe_id' => $this->ligne->employe_id, 'debut_le' => aujourdhui()->addWeek(), 'fin_le' => aujourdhui()->addWeek()]);
        Absence::factory()->create(['employe_id' => $collegue->employe_id]);

        $this->getJson("/api/v1/absences?employe_id={$this->ligne->employe_id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $recente->id)
            ->assertJsonPath('data.1.id', $ancienne->id);
    }

    public function test_la_creation_refuse_un_refus_impose_une_periode_a_l_envers_et_un_employe_d_une_clinique_soeur(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $demande = [
            'employe_id' => $this->ligne->employe_id,
            'type' => 'maladie',
            'debut_le' => aujourdhui()->addDays(2)->toDateString(),
            'fin_le' => aujourdhui()->addDays(3)->toDateString(),
        ];

        // « ref » est l'issue d'un refus, jamais un statut de départ
        $this->postJson('/api/v1/absences', [...$demande, 'statut' => 'ref'], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');

        $this->postJson('/api/v1/absences', [...$demande, 'fin_le' => aujourdhui()->addDay()->toDateString()], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fin_le');

        $this->postJson('/api/v1/absences', [...$demande, 'type' => 'vacances'], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        // Même organisation, mais affecté à la seule clinique sœur : hors de cette grille
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $collegue = Employe::factory()->dans($soeur)->create();
        $this->postJson('/api/v1/absences', [...$demande, 'employe_id' => $collegue->id], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employe_id');

        $this->assertSame(0, Absence::query()->withoutGlobalScopes()->count());
    }

    public function test_l_absence_d_un_collegue_de_la_clinique_soeur_est_introuvable_ici(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $collegue = Employe::factory()->dans($soeur)->create();
        $absence = Absence::factory()->enAttente()->create(['employe_id' => $collegue->id]);
        $entetes = $this->enTetes($this->jeton);

        $this->getJson("/api/v1/absences/{$absence->id}", $entetes)->assertNotFound();
        $this->postJson("/api/v1/absences/{$absence->id}/valider", [], $entetes)->assertNotFound();
        $this->assertSame('att', $absence->refresh()->statut->value);
    }
}
