<?php

namespace Tests\Feature\Bloc;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Intervention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le bloc opératoire a son propre domaine de permission, vérifié en
 * base à chaque requête : sans lui, tout le module refuse en 403.
 */
class BlocPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
    }

    public function test_chaque_endpoint_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        $intervention = Intervention::factory()->create(['clinique_id' => $this->clinique->id]);
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/bloc/ressources', $entetes)->assertForbidden();
        $this->getJson('/api/v1/bloc/interventions', $entetes)->assertForbidden();
        $this->postJson('/api/v1/bloc/interventions', [], $entetes)->assertForbidden();
        $this->getJson("/api/v1/bloc/interventions/{$intervention->id}", $entetes)->assertForbidden();
        $this->patchJson("/api/v1/bloc/interventions/{$intervention->id}", [], $entetes)->assertForbidden();
        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/avancer", [], $entetes)->assertForbidden();
        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/checklist", ['libelle' => 'x'], $entetes)->assertForbidden();
        $this->putJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/jeun", ['est_coche' => true], $entetes)->assertForbidden();
        $this->deleteJson("/api/v1/bloc/interventions/{$intervention->id}/checklist/jeun", [], $entetes)->assertForbidden();
        $this->postJson("/api/v1/bloc/interventions/{$intervention->id}/consentement", [], $entetes)->assertForbidden();
    }

    public function test_une_derogation_retirant_le_domaine_bloc_ferme_le_module(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Bloc,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/bloc/interventions', $this->enTetes($jeton))->assertForbidden();

        // L'hospitalisation (domaine patients) reste ouverte.
        $this->getJson('/api/v1/hospitalisation/sejours', $this->enTetes($jeton))->assertOk();
    }

    public function test_un_role_avec_le_domaine_bloc_accede_au_module(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/bloc/interventions', $this->enTetes($jeton))->assertOk();
    }
}
