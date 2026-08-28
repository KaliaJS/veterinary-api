<?php

namespace Tests\Feature\Planning;

use App\Enums\DomainePermission;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\DerogationPermission;
use App\Models\LignePlanning;
use App\Models\ListeAttente;
use App\Models\RendezVous;
use App\Models\UrgenceAttente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Permissions du domaine planning, vérifiées en base à chaque requête :
 * sans le domaine, chaque endpoint refuse en 403 — les mutations
 * d'absence relèvent du domaine `equipe` (écran Équipe), leur lecture
 * du domaine `planning` (elles dessinent la grille).
 */
class PlanningPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private RendezVous $rendezVous;

    private Absence $absence;

    private UrgenceAttente $urgence;

    private DemandeRendezVous $demande;

    private ListeAttente $inscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
        ]);
        $this->absence = Absence::factory()->enAttente()->create(['employe_id' => $this->ligne->employe_id]);
        $this->urgence = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->demande = DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->inscription = ListeAttente::factory()->create(['clinique_id' => $this->clinique->id]);
    }

    public function test_chaque_endpoint_du_planning_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        foreach ($this->appelsDuDomaine() as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_les_mutations_d_absence_exigent_le_domaine_equipe(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Equipe,
            'est_accordee' => false,
        ]);

        $entetes = $this->enTetes($jeton);

        // Lecture : domaine planning, toujours permis à ce rôle.
        $this->getJson('/api/v1/absences', $entetes)->assertOk();

        // Mutations : domaine equipe, retiré par la dérogation.
        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id,
            'type' => 'conge',
            'debut_le' => today()->toDateString(),
            'fin_le' => today()->toDateString(),
        ], $entetes)->assertForbidden();
        $this->postJson("/api/v1/absences/{$this->absence->id}/valider", [], $entetes)->assertForbidden();
        $this->postJson("/api/v1/absences/{$this->absence->id}/refuser", [], $entetes)->assertForbidden();
        $this->patchJson("/api/v1/absences/{$this->absence->id}", [], $entetes)->assertForbidden();
        $this->deleteJson("/api/v1/absences/{$this->absence->id}", [], $entetes)->assertForbidden();
    }

    public function test_la_lecture_des_absences_exige_le_domaine_planning(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Planning,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/absences', $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_un_role_avec_le_domaine_planning_accede_au_domaine(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/lignes-planning', $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/horaires', $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/planning/employes', $this->enTetes($jeton))->assertOk();
    }

    /**
     * Chaque endpoint du domaine avec une charge qui passe la
     * validation : l'autorisation (contrôleur) vient après la
     * FormRequest, une charge invalide répondrait 422 avant le 403.
     *
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function appelsDuDomaine(): array
    {
        $demain = today()->addDay()->setTime(10, 0)->toISOString();
        $rendezVous = $this->rendezVous->id;
        $absence = $this->absence->id;

        return [
            ['getJson', '/api/v1/lignes-planning', []],
            ['postJson', '/api/v1/lignes-planning', ['employe_id' => $this->ligne->employe_id, 'nom' => 'Dr Test', 'role' => 'consult', 'groupe' => 'Consultation']],
            ['getJson', "/api/v1/lignes-planning/{$this->ligne->id}", []],
            ['patchJson', "/api/v1/lignes-planning/{$this->ligne->id}", []],
            ['getJson', '/api/v1/horaires', []],
            ['getJson', '/api/v1/planning/employes', []],
            ['putJson', '/api/v1/horaires', ['ouverture' => '08:00', 'fermeture' => '19:30', 'pause_active' => false]],
            ['getJson', '/api/v1/planning/disponibilites?date='.today()->toDateString(), []],
            ['getJson', '/api/v1/rendez-vous', []],
            ['postJson', '/api/v1/rendez-vous', ['ligne_planning_id' => $this->ligne->id, 'starts_at' => $demain, 'duree_minutes' => 20]],
            ['postJson', '/api/v1/rendez-vous/prochain-creneau', []],
            ['getJson', "/api/v1/rendez-vous/{$rendezVous}", []],
            ['patchJson', "/api/v1/rendez-vous/{$rendezVous}", []],
            ['deleteJson', "/api/v1/rendez-vous/{$rendezVous}", []],
            ['getJson', "/api/v1/rendez-vous/{$rendezVous}/audits", []],
            ['postJson', "/api/v1/rendez-vous/{$rendezVous}/statut", ['status' => 'salle']],
            ['postJson', "/api/v1/rendez-vous/{$rendezVous}/non-venu", []],
            ['postJson', "/api/v1/rendez-vous/{$rendezVous}/annuler", []],
            ['postJson', "/api/v1/rendez-vous/{$rendezVous}/deplacer", ['starts_at' => $demain]],
            ['postJson', "/api/v1/rendez-vous/{$rendezVous}/redimensionner", ['duree_minutes' => 30]],
            ['getJson', '/api/v1/absences', []],
            ['postJson', '/api/v1/absences', ['employe_id' => $this->ligne->employe_id, 'type' => 'conge', 'debut_le' => today()->toDateString(), 'fin_le' => today()->toDateString()]],
            ['getJson', "/api/v1/absences/{$absence}", []],
            ['patchJson', "/api/v1/absences/{$absence}", []],
            ['deleteJson', "/api/v1/absences/{$absence}", []],
            ['postJson', "/api/v1/absences/{$absence}/valider", []],
            ['postJson', "/api/v1/absences/{$absence}/refuser", []],
            ['getJson', '/api/v1/urgences', []],
            ['postJson', '/api/v1/urgences', ['patient_name' => 'Filou', 'owner_name' => 'M. Test', 'reason' => 'Urgence', 'triage' => 2, 'duree_minutes' => 20]],
            ['postJson', "/api/v1/urgences/{$this->urgence->id}/placer", []],
            ['deleteJson', "/api/v1/urgences/{$this->urgence->id}", []],
            ['getJson', '/api/v1/demandes', []],
            ['postJson', "/api/v1/demandes/{$this->demande->id}/placer", []],
            ['deleteJson', "/api/v1/demandes/{$this->demande->id}", []],
            ['getJson', '/api/v1/liste-attente', []],
            ['postJson', '/api/v1/liste-attente', ['patient_name' => 'Caramel', 'owner_name' => 'Mme Test', 'reason' => 'Suivi', 'duree_minutes' => 20]],
            ['postJson', "/api/v1/liste-attente/{$this->inscription->id}/placer", []],
            ['deleteJson', "/api/v1/liste-attente/{$this->inscription->id}", []],
        ];
    }
}
