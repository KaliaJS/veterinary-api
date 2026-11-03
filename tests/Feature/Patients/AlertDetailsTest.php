<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\RendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les précisions des alertes (demande de Jim, 02.11.2026, fenêtre
 * « Nouveau patient » A) : agressif dans quels cas, muselière ; allergique
 * à quoi. Chaque test suit une case de notes/todo.md (dépôt web).
 */
class AlertDetailsTest extends TestCase
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

    private function fiche(): Client
    {
        return Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
    }

    public function test_agressif_dit_dans_quels_cas_et_si_la_museliere_est_necessaire(): void
    {
        $data = $this->postJson('/api/v1/patients', [
            'client_id' => $this->fiche()->id, 'nom' => 'Rex', 'espece' => 'CH', 'sexe' => 'Mâle',
            'est_agressif' => true, 'aggression_triggers' => ['handling', 'injections', 'men'], 'needs_muzzle' => true,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $this->assertSame(['handling', 'injections', 'men'], $data['aggression_triggers']);
        $this->assertTrue($data['needs_muzzle']);

        // Un cas inconnu est refusé
        $this->putJson("/api/v1/patients/{$data['id']}", ['aggression_triggers' => ['lune']], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('aggression_triggers.0');
    }

    public function test_allergies_disent_a_quoi(): void
    {
        $data = $this->postJson('/api/v1/patients', [
            'client_id' => $this->fiche()->id, 'nom' => 'Moka', 'espece' => 'CT', 'sexe' => 'Femelle',
            'a_allergies' => true, 'allergens' => ['penicillins', 'nsaids'], 'allergy_note' => 'Amoxicilline, poulet',
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $this->assertSame(['penicillins', 'nsaids'], $data['allergens']);
        $this->assertSame('Amoxicilline, poulet', $data['allergy_note']);

        $this->putJson("/api/v1/patients/{$data['id']}", ['allergens' => ['chocolat']], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('allergens.0');
    }

    public function test_une_alerte_retiree_retire_ses_precisions(): void
    {
        $patient = Patient::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'client_id' => $this->fiche()->id,
            'est_agressif' => true, 'aggression_triggers' => ['dogs'], 'needs_muzzle' => true,
            'a_allergies' => true, 'allergens' => ['vaccines'], 'allergy_note' => 'Réaction au rappel 2025',
        ]);

        $data = $this->putJson("/api/v1/patients/{$patient->id}", ['est_agressif' => false, 'a_allergies' => false], $this->enTetes($this->jeton))
            ->assertOk()->json('data');

        $this->assertSame([], $data['aggression_triggers']);
        $this->assertFalse($data['needs_muzzle']);
        $this->assertSame([], $data['allergens']);
        $this->assertNull($data['allergy_note']);
    }

    public function test_toutes_les_valeurs_sont_acceptees_une_seule_fois(): void
    {
        $tous = ['handling', 'injections', 'men', 'women', 'dogs', 'cats', 'pain'];
        $familles = ['penicillins', 'cephalosporins', 'sulfonamides', 'nsaids', 'anesthetics', 'vaccines', 'food', 'antiparasitics'];
        $data = $this->postJson('/api/v1/patients', [
            'client_id' => $this->fiche()->id, 'nom' => 'Rex', 'espece' => 'CH', 'sexe' => 'Mâle',
            'est_agressif' => true, 'aggression_triggers' => $tous, 'a_allergies' => true, 'allergens' => $familles,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $this->assertSame($tous, $data['aggression_triggers']);
        $this->assertSame($familles, $data['allergens']);

        // Un même cas deux fois est refusé
        $this->putJson("/api/v1/patients/{$data['id']}", ['aggression_triggers' => ['men', 'men'], 'allergens' => ['food', 'food']], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['aggression_triggers.0', 'allergens.0']);
    }

    public function test_le_planning_sert_les_precisions_avec_le_rendez_vous(): void
    {
        $patient = Patient::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'client_id' => $this->fiche()->id,
            'est_agressif' => true, 'aggression_triggers' => ['injections'], 'needs_muzzle' => true,
            'a_allergies' => true, 'allergens' => ['penicillins'], 'allergy_note' => 'Poulet',
        ]);
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id]);

        $alertes = $this->getJson('/api/v1/rendez-vous', $this->enTetes($this->jeton))->assertOk()->json('data.0.patient_alerts');

        $this->assertSame([
            'est_agressif' => true, 'aggression_triggers' => ['injections'], 'needs_muzzle' => true,
            'a_allergies' => true, 'allergens' => ['penicillins'], 'allergy_note' => 'Poulet',
        ], $alertes);
    }
}
