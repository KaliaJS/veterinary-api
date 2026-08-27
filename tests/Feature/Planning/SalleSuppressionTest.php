<?php

namespace Tests\Feature\Planning;

use App\Enums\DomainePermission;
use App\Enums\StatutIntervention;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Intervention;
use App\Models\LignePlanning;
use App\Models\Salle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * DELETE /salles/{salle} — réservé aux salles de bloc (l'écran du bloc
 * gère sa liste, docs/data-model.md §2 et §11) : refusé si des
 * interventions non terminées y sont rattachées, si elle porte une
 * ligne du planning, ou si c'est la dernière salle de bloc de la
 * clinique. Les interventions terminées gardent le nom dénormalisé.
 */
class SalleSuppressionTest extends TestCase
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

    private function salleBloc(string $nom): Salle
    {
        return Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id, 'nom' => $nom]);
    }

    public function test_une_salle_de_bloc_libre_se_supprime(): void
    {
        $bloc1 = $this->salleBloc('Bloc 1');
        $bloc2 = $this->salleBloc('Bloc 2');

        $this->deleteJson("/api/v1/salles/{$bloc2->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('salles', ['id' => $bloc2->id]);
        $this->assertDatabaseHas('salles', ['id' => $bloc1->id]);
    }

    public function test_une_salle_avec_des_interventions_non_terminees_est_refusee(): void
    {
        $this->salleBloc('Bloc 1');
        $bloc2 = $this->salleBloc('Bloc 2');

        Intervention::factory()->create([
            'clinique_id' => $this->clinique->id,
            'salle_id' => $bloc2->id,
            'salle_nom' => $bloc2->nom,
            'statut' => StatutIntervention::Preparation,
        ]);

        $this->deleteJson("/api/v1/salles/{$bloc2->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('salle');
    }

    public function test_les_interventions_terminees_gardent_le_nom_de_la_salle_supprimee(): void
    {
        $this->salleBloc('Bloc 1');
        $bloc2 = $this->salleBloc('Bloc 2');

        $intervention = Intervention::factory()->finie()->create([
            'clinique_id' => $this->clinique->id,
            'salle_id' => $bloc2->id,
            'salle_nom' => $bloc2->nom,
        ]);

        $this->deleteJson("/api/v1/salles/{$bloc2->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $intervention->refresh();
        $this->assertNull($intervention->salle_id);
        $this->assertSame('Bloc 2', $intervention->salle_nom);
    }

    public function test_une_salle_de_consultation_ne_se_supprime_pas(): void
    {
        $consultation = Salle::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->deleteJson("/api/v1/salles/{$consultation->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable();
    }

    public function test_la_derniere_salle_de_bloc_ne_se_supprime_pas(): void
    {
        $seule = $this->salleBloc('Bloc 1');
        // Une salle de bloc d'une clinique sœur ne compte pas.
        Salle::factory()->bloc()->create([
            'clinique_id' => Clinique::factory()->create(['societe_id' => $this->clinique->societe_id])->id,
        ]);

        $this->deleteJson("/api/v1/salles/{$seule->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable();
    }

    public function test_une_salle_portant_une_ligne_du_planning_est_refusee(): void
    {
        $this->salleBloc('Bloc 2');
        $bloc1 = $this->salleBloc('Bloc 1');

        LignePlanning::factory()->create([
            'clinique_id' => $this->clinique->id,
            'salle_id' => $bloc1->id,
        ]);

        $this->deleteJson("/api/v1/salles/{$bloc1->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable();
    }

    public function test_une_salle_d_une_clinique_soeur_est_introuvable(): void
    {
        $ailleurs = Salle::factory()->bloc()->create([
            'clinique_id' => Clinique::factory()->create(['societe_id' => $this->clinique->societe_id])->id,
        ]);

        $this->deleteJson("/api/v1/salles/{$ailleurs->id}", [], $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_le_delete_exige_le_domaine_planning(): void
    {
        $this->salleBloc('Bloc 1');
        $bloc2 = $this->salleBloc('Bloc 2');

        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Planning,
            'est_accordee' => false,
        ]);

        $this->deleteJson("/api/v1/salles/{$bloc2->id}", [], $this->enTetes($jeton))
            ->assertForbidden();
    }
}
