<?php

namespace Tests\Feature\Planning;

use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Employe;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\ListeAttente;
use App\Models\RendezVous;
use App\Models\UrgenceAttente;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Isolation multi-clinique du domaine planning : un jeton de la
 * clinique A ne lit, ne modifie ni ne référence jamais les données de
 * la clinique B — 404 sur les ressources (ne pas révéler l'existence),
 * 422 sur les références de validation.
 */
class PlanningIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $cliniqueA;

    private Clinique $cliniqueB;

    private LignePlanning $ligneA;

    private LignePlanning $ligneB;

    private RendezVous $rendezVousA;

    private RendezVous $rendezVousB;

    private Absence $absenceA;

    private Absence $absenceB;

    private UrgenceAttente $urgenceA;

    private UrgenceAttente $urgenceB;

    private DemandeRendezVous $demandeA;

    private DemandeRendezVous $demandeB;

    private ListeAttente $inscriptionA;

    private ListeAttente $inscriptionB;

    private string $jetonA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliniqueA = Clinique::factory()->create();
        $this->cliniqueB = Clinique::factory()->create();

        foreach (['A', 'B'] as $suffixe) {
            $clinique = $suffixe === 'A' ? $this->cliniqueA : $this->cliniqueB;

            $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
            $this->{'ligne'.$suffixe} = $ligne;
            $this->{'rendezVous'.$suffixe} = RendezVous::factory()->create([
                'clinique_id' => $clinique->id,
                'ligne_planning_id' => $ligne->id,
            ]);
            $this->{'absence'.$suffixe} = Absence::factory()->enAttente()->create([
                'employe_id' => $ligne->employe_id,
            ]);
            $this->{'urgence'.$suffixe} = UrgenceAttente::factory()->create(['clinique_id' => $clinique->id]);
            $this->{'demande'.$suffixe} = DemandeRendezVous::factory()->create(['clinique_id' => $clinique->id]);
            $this->{'inscription'.$suffixe} = ListeAttente::factory()->create(['clinique_id' => $clinique->id]);
        }

        ['jeton' => $this->jetonA] = $this->employeAvecJeton($this->cliniqueA);
    }

    public function test_les_listes_ne_montrent_que_la_clinique_du_jeton(): void
    {
        $listes = [
            '/api/v1/lignes-planning' => $this->ligneA->id,
            '/api/v1/rendez-vous' => $this->rendezVousA->id,
            '/api/v1/absences' => $this->absenceA->id,
            '/api/v1/urgences' => $this->urgenceA->id,
            '/api/v1/demandes' => $this->demandeA->id,
            '/api/v1/liste-attente' => $this->inscriptionA->id,
        ];

        foreach ($listes as $uri => $idAttendu) {
            $reponse = $this->getJson($uri, $this->enTetes($this->jetonA))->assertOk();

            $this->assertSame([$idAttendu], array_column($reponse->json('data'), 'id'), "Liste {$uri}");
        }
    }

    public function test_toute_ressource_d_une_autre_clinique_repond_introuvable(): void
    {
        $rendezVousB = $this->rendezVousB->id;
        $absenceB = $this->absenceB->id;

        $appels = [
            ['getJson', "/api/v1/lignes-planning/{$this->ligneB->id}", []],
            ['patchJson', "/api/v1/lignes-planning/{$this->ligneB->id}", ['nom' => 'Piratée']],
            ['getJson', "/api/v1/rendez-vous/{$rendezVousB}", []],
            ['patchJson', "/api/v1/rendez-vous/{$rendezVousB}", []],
            ['deleteJson', "/api/v1/rendez-vous/{$rendezVousB}", []],
            ['getJson', "/api/v1/rendez-vous/{$rendezVousB}/audits", []],
            ['postJson', "/api/v1/rendez-vous/{$rendezVousB}/statut", ['status' => 'salle']],
            ['postJson', "/api/v1/rendez-vous/{$rendezVousB}/non-venu", []],
            ['postJson', "/api/v1/rendez-vous/{$rendezVousB}/annuler", []],
            ['postJson', "/api/v1/rendez-vous/{$rendezVousB}/deplacer", ['starts_at' => now()->toISOString()]],
            ['postJson', "/api/v1/rendez-vous/{$rendezVousB}/redimensionner", ['duree_minutes' => 30]],
            ['getJson', "/api/v1/absences/{$absenceB}", []],
            ['patchJson', "/api/v1/absences/{$absenceB}", []],
            ['deleteJson', "/api/v1/absences/{$absenceB}", []],
            ['postJson', "/api/v1/absences/{$absenceB}/valider", []],
            ['postJson', "/api/v1/absences/{$absenceB}/refuser", []],
            ['deleteJson', "/api/v1/urgences/{$this->urgenceB->id}", []],
            ['postJson', "/api/v1/urgences/{$this->urgenceB->id}/placer", []],
            ['deleteJson', "/api/v1/demandes/{$this->demandeB->id}", []],
            ['postJson', "/api/v1/demandes/{$this->demandeB->id}/placer", []],
            ['deleteJson', "/api/v1/liste-attente/{$this->inscriptionB->id}", []],
            ['postJson', "/api/v1/liste-attente/{$this->inscriptionB->id}/placer", []],
        ];

        foreach ($appels as [$methode, $uri, $charge]) {
            $this->appeler($methode, $uri, $charge)->assertNotFound();
        }

        // Rien n'a bougé côté B.
        $this->assertNotSame('annule', RendezVous::withoutGlobalScopes()->find($rendezVousB)->status->value);
        $this->assertDatabaseHas('urgences_attente', ['id' => $this->urgenceB->id]);
    }

    public function test_referencer_une_ressource_d_une_autre_clinique_est_refuse_par_la_validation(): void
    {
        $entetes = $this->enTetes($this->jetonA);

        $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligneB->id,
            'starts_at' => aujourdhui()->addDay()->setTime(10, 0)->toISOString(),
            'duree_minutes' => 20,
        ], $entetes)->assertUnprocessable()->assertJsonValidationErrors('ligne_planning_id');

        $employeB = Employe::withoutGlobalScopes()->findOrFail($this->ligneB->employe_id);

        $this->postJson('/api/v1/absences', [
            'employe_id' => $employeB->id,
            'type' => 'conge',
            'debut_le' => aujourdhui()->toDateString(),
            'fin_le' => aujourdhui()->toDateString(),
        ], $entetes)->assertUnprocessable()->assertJsonValidationErrors('employe_id');

        $this->postJson("/api/v1/urgences/{$this->urgenceA->id}/placer", [
            'ligne_planning_id' => $this->ligneB->id,
            'starts_at' => aujourdhui()->addDay()->setTime(10, 0)->toISOString(),
        ], $entetes)->assertUnprocessable()->assertJsonValidationErrors('ligne_planning_id');
    }

    public function test_les_horaires_sont_ceux_de_la_clinique_du_jeton(): void
    {
        HoraireOuverture::factory()->create(['clinique_id' => $this->cliniqueA->id, 'ouverture' => '09:00']);
        HoraireOuverture::factory()->create(['clinique_id' => $this->cliniqueB->id, 'ouverture' => '07:00']);

        $entetes = $this->enTetes($this->jetonA);

        $this->getJson('/api/v1/horaires', $entetes)->assertOk()->assertJsonPath('data.ouverture', '09:00');

        $this->putJson('/api/v1/horaires', [
            'ouverture' => '10:00',
            'fermeture' => '18:00',
            'pause_active' => false,
        ], $entetes)->assertOk();

        $this->assertSame(
            '07:00',
            substr(HoraireOuverture::withoutGlobalScopes()->where('clinique_id', $this->cliniqueB->id)->sole()->ouverture, 0, 5),
        );
    }

    public function test_les_disponibilites_ignorent_les_lignes_des_autres_cliniques(): void
    {
        $lundi = CarbonImmutable::today()->next('Monday');

        $reponse = $this->getJson(
            '/api/v1/planning/disponibilites?date='.$lundi->toDateString(),
            $this->enTetes($this->jetonA),
        )->assertOk();

        $this->assertSame(
            [$this->ligneA->id],
            array_values(array_unique(array_column($reponse->json('data.creneaux'), 'ligne_planning_id'))),
        );
    }

    public function test_une_creation_est_rattachee_a_la_clinique_du_jeton_meme_forcee(): void
    {
        $reponse = $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligneA->id,
            'starts_at' => aujourdhui()->addDay()->setTime(10, 0)->toISOString(),
            'duree_minutes' => 20,
            'clinique_id' => $this->cliniqueB->id,
        ], $this->enTetes($this->jetonA))->assertCreated();

        $this->assertSame(
            $this->cliniqueA->id,
            RendezVous::withoutGlobalScopes()->find($reponse->json('data.id'))->clinique_id,
        );
    }

    private function appeler(string $methode, string $uri, array $charge): TestResponse
    {
        $entetes = $this->enTetes($this->jetonA);

        return $methode === 'getJson'
            ? $this->getJson($uri, $entetes)
            : $this->{$methode}($uri, $charge, $entetes);
    }
}
