<?php

namespace Tests\Feature\Tele;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\SessionTeleconsultation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La téléconsultation relève du domaine `planning` (la session pilote
 * les statuts du RDV), vérifié en base à chaque requête.
 */
class TelePermissionsTest extends TestCase
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

        $session = SessionTeleconsultation::factory()->create(['clinique_id' => $this->clinique->id]);
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/tele/sessions', $entetes)->assertForbidden();
        $this->postJson('/api/v1/tele/sessions', [], $entetes)->assertForbidden();
        $this->getJson("/api/v1/tele/sessions/{$session->id}", $entetes)->assertForbidden();
        $this->postJson("/api/v1/tele/sessions/{$session->id}/terminer", ['cloturer' => false], $entetes)->assertForbidden();
    }

    public function test_une_derogation_retirant_le_domaine_planning_ferme_la_teleconsultation(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Planning,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/tele/sessions', $this->enTetes($jeton))->assertForbidden();

        // L'hospitalisation (domaine patients) reste ouverte.
        $this->getJson('/api/v1/hospitalisation/sejours', $this->enTetes($jeton))->assertOk();
    }

    public function test_un_role_avec_le_domaine_planning_accede_a_la_teleconsultation(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/tele/sessions', $this->enTetes($jeton))->assertOk();
    }
}
