<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\DomainePermission;
use App\Models\Analyse;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le laboratoire relève du domaine `patients` (dossier médical —
 * l'ASV tient la paillasse), vérifié en base à chaque requête.
 */
class LaboratoirePermissionsTest extends TestCase
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

        $analyse = Analyse::factory()->create(['clinique_id' => $this->clinique->id]);
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/laboratoire/panels', $entetes)->assertForbidden();
        $this->getJson('/api/v1/laboratoire/analyses', $entetes)->assertForbidden();
        $this->postJson('/api/v1/laboratoire/analyses', [], $entetes)->assertForbidden();
        $this->getJson("/api/v1/laboratoire/analyses/{$analyse->id}", $entetes)->assertForbidden();
        $this->patchJson("/api/v1/laboratoire/analyses/{$analyse->id}", [], $entetes)->assertForbidden();
        $this->postJson("/api/v1/laboratoire/analyses/{$analyse->id}/avancer", [], $entetes)->assertForbidden();
        $this->putJson("/api/v1/laboratoire/analyses/{$analyse->id}/resultats", [], $entetes)->assertForbidden();
    }

    public function test_une_derogation_retirant_le_domaine_patients_ferme_le_laboratoire(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Patients,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/laboratoire/analyses', $this->enTetes($jeton))->assertForbidden();

        // Le bloc (son propre domaine) reste ouvert.
        $this->getJson('/api/v1/bloc/interventions', $this->enTetes($jeton))->assertOk();
    }

    public function test_un_role_avec_le_domaine_patients_accede_au_laboratoire(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/laboratoire/analyses', $this->enTetes($jeton))->assertOk();
    }
}
