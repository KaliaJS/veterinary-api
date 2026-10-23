<?php

namespace Tests\Feature\Planning;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\LignePlanning;
use App\Models\Salle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Colonnes du planning, parcours nominal : une colonne de praticien ou de
 * ressource (salle) se crée, se lit, se renomme et se réordonne ; elle
 * passe d'un praticien à une salle sans jamais porter les deux ni aucun.
 * Elle ne se supprime pas : ses rendez-vous y restent accrochés.
 */
class LignesPlanningParcoursTest extends TestCase
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

    public function test_une_colonne_de_praticien_se_cree_se_lit_et_se_reordonne(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $premiere = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'ordre' => 1]);
        $praticien = Employe::factory()->dans($this->clinique)->create(['nom' => 'Dr Léa Fontaine', 'initiales' => 'LF']);

        $id = $this->postJson('/api/v1/lignes-planning', [
            'employe_id' => $praticien->id,
            'nom' => 'Dr Léa Fontaine',
            'sous_titre' => 'CONSULTATION',
            'role' => 'consult',
            'initiales' => 'LF',
            'groupe' => 'Consultation',
            'ordre' => 2,
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.employe_id', $praticien->id)
            ->assertJsonPath('data.salle_id', null)
            ->assertJsonPath('data.role', 'consult')
            ->json('data.id');

        $this->assertSame($this->clinique->id, LignePlanning::query()->withoutGlobalScopes()->findOrFail($id)->clinique_id);

        $this->getJson("/api/v1/lignes-planning/{$id}", $entetes)
            ->assertOk()
            ->assertJsonPath('data.nom', 'Dr Léa Fontaine')
            ->assertJsonPath('data.initiales', 'LF')
            ->assertJsonPath('data.groupe', 'Consultation')
            ->assertJsonPath('data.ordre', 2);

        $this->getJson('/api/v1/lignes-planning', $entetes)
            ->assertOk()
            ->assertJsonPath('data.0.id', $premiere->id)
            ->assertJsonPath('data.1.id', $id);

        // Renommée et passée en tête de la grille
        $this->patchJson("/api/v1/lignes-planning/{$id}", [
            'nom' => 'Dr Léa Fontaine Meyer',
            'sous_titre' => null,
            'ordre' => 0,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.nom', 'Dr Léa Fontaine Meyer')
            ->assertJsonPath('data.sous_titre', null)
            ->assertJsonPath('data.employe_id', $praticien->id);

        $this->getJson('/api/v1/lignes-planning', $entetes)
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.1.id', $premiere->id);
    }

    public function test_une_colonne_de_ressource_porte_une_salle_et_peut_passer_a_un_praticien(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $bloc = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id]);
        $praticien = Employe::factory()->dans($this->clinique)->create();

        $id = $this->postJson('/api/v1/lignes-planning', [
            'salle_id' => $bloc->id,
            'nom' => 'Bloc 1',
            'role' => 'bloc',
            'groupe' => 'Chirurgie',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.salle_id', $bloc->id)
            ->assertJsonPath('data.employe_id', null)
            ->assertJsonPath('data.ordre', 0)
            ->json('data.id');

        // Sans ordre donné : la colonne prend l'ordre par défaut
        $this->getJson("/api/v1/lignes-planning/{$id}", $entetes)->assertOk()->assertJsonPath('data.ordre', 0);

        $this->patchJson("/api/v1/lignes-planning/{$id}", [
            'salle_id' => null,
            'employe_id' => $praticien->id,
            'role' => 'consult',
            'groupe' => 'Consultation',
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.salle_id', null)
            ->assertJsonPath('data.employe_id', $praticien->id)
            ->assertJsonPath('data.role', 'consult');
    }

    public function test_une_colonne_porte_un_praticien_ou_une_salle_jamais_les_deux_ni_aucun(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $bloc = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id]);
        $praticien = Employe::factory()->dans($this->clinique)->create();
        $base = ['nom' => 'Colonne', 'role' => 'consult', 'groupe' => 'Consultation'];

        $this->postJson('/api/v1/lignes-planning', [...$base, 'employe_id' => $praticien->id, 'salle_id' => $bloc->id], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('salle_id');
        $this->postJson('/api/v1/lignes-planning', $base, $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employe_id', 'salle_id']);

        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->patchJson("/api/v1/lignes-planning/{$ligne->id}", ['salle_id' => $bloc->id], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('salle_id');
        $this->patchJson("/api/v1/lignes-planning/{$ligne->id}", ['employe_id' => null], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employe_id');

        $this->assertSame($ligne->employe_id, $ligne->refresh()->employe_id);
        $this->assertNull($ligne->salle_id);
    }

    public function test_la_validation_refuse_une_salle_d_une_autre_clinique_un_role_inconnu_et_des_initiales_trop_longues(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $salleAilleurs = Salle::factory()->bloc()->create(['clinique_id' => Clinique::factory()->create(['societe_id' => $this->clinique->societe_id])->id]);

        $this->postJson('/api/v1/lignes-planning', [
            'salle_id' => $salleAilleurs->id,
            'nom' => 'Bloc de la sœur',
            'role' => 'bloc',
            'groupe' => 'Chirurgie',
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('salle_id');

        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->patchJson("/api/v1/lignes-planning/{$ligne->id}", ['role' => 'garde', 'initiales' => 'ABCD', 'ordre' => 300], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role', 'initiales', 'ordre']);
    }

    public function test_une_colonne_ne_se_supprime_pas(): void
    {
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->deleteJson("/api/v1/lignes-planning/{$ligne->id}", [], $this->enTetes($this->jeton))
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('lignes_planning', ['id' => $ligne->id]);
    }
}
