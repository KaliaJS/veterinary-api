<?php

namespace Tests\Feature\Imagerie;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\ExamenImagerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'imagerie relève du domaine `patients` (dossier médical), vérifié
 * en base à chaque requête.
 */
class ImageriePermissionsTest extends TestCase
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

        $examen = ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id]);
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/imagerie/trames', $entetes)->assertForbidden();
        $this->getJson('/api/v1/imagerie/examens', $entetes)->assertForbidden();
        $this->postJson('/api/v1/imagerie/examens', [], $entetes)->assertForbidden();
        $this->getJson("/api/v1/imagerie/examens/{$examen->id}", $entetes)->assertForbidden();
        $this->patchJson("/api/v1/imagerie/examens/{$examen->id}", [], $entetes)->assertForbidden();
        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/compte-rendu", [], $entetes)->assertForbidden();
        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/cliches", [], $entetes)->assertForbidden();
    }

    public function test_une_derogation_retirant_le_domaine_patients_ferme_l_imagerie(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Patients,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/imagerie/examens', $this->enTetes($jeton))->assertForbidden();

        // Le bloc (son propre domaine) reste ouvert.
        $this->getJson('/api/v1/bloc/interventions', $this->enTetes($jeton))->assertOk();
    }

    public function test_un_role_avec_le_domaine_patients_accede_a_l_imagerie(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/imagerie/examens', $this->enTetes($jeton))->assertOk();
    }
}
