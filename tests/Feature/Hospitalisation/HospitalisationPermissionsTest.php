<?php

namespace Tests\Feature\Hospitalisation;

use App\Enums\DomainePermission;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Sejour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'hospitalisation relève du domaine patients — l'ASV la tient autant
 * que le vétérinaire. La facture de sortie est un effet système : elle
 * naît même sans le domaine facturation, mais la réponse ne l'expose
 * qu'à qui peut la voir.
 */
class HospitalisationPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();
    }

    public function test_chaque_endpoint_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/hospitalisation/sejours', $entetes)->assertForbidden();
        $this->postJson('/api/v1/hospitalisation/sejours', ['patient_id' => $this->patient->id, 'box' => 1, 'motif' => 'x'], $entetes)->assertForbidden();
        $this->getJson("/api/v1/hospitalisation/sejours/{$sejour->id}", $entetes)->assertForbidden();
        $this->patchJson("/api/v1/hospitalisation/sejours/{$sejour->id}", ['note' => 'x'], $entetes)->assertForbidden();
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins", ['prevu_a' => 600, 'acte' => 'x'], $entetes)->assertForbidden();
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/constantes", ['mesure_a' => 600, 'temperature' => 38, 'fc' => 90, 'fr' => 20], $entetes)->assertForbidden();
        $this->getJson("/api/v1/hospitalisation/sejours/{$sejour->id}/facture", $entetes)->assertForbidden();
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/sortie", [], $entetes)->assertForbidden();
    }

    public function test_une_derogation_retirant_le_domaine_patients_ferme_l_hospitalisation(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Patients,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/hospitalisation/sejours', $this->enTetes($jeton))->assertForbidden();

        // Le planning, lui, reste ouvert.
        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jeton))->assertOk();
    }

    public function test_la_sortie_sans_domaine_facturation_facture_quand_meme_sans_l_exposer(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Facturation,
            'est_accordee' => false,
        ]);

        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/sortie", [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonMissingPath('facture');

        // L'effet système a bien eu lieu.
        $this->assertNotNull(Facture::withoutGlobalScopes()->sole());
        $this->assertNotNull($sejour->refresh()->facture_id);
    }
}
