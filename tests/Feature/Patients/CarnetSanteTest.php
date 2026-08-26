<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Carnet de santé côté fiche : saisie de puce (15 chiffres, stockée en
 * groupes de trois) et déclaration AMICUS (chiens pucés uniquement —
 * la déclaration exige une puce, docs/data-model.md §16).
 */
class CarnetSanteTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Client $client;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->client = Client::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_la_puce_saisie_librement_est_normalisee(): void
    {
        $patient = $this->patient(['puce' => null]);

        $this->putJson("/api/v1/patients/{$patient->id}/puce", [
            'puce' => '756-091 234.567890',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.puce', '756 091 234 567 890');
    }

    public function test_une_puce_incomplete_est_refusee(): void
    {
        $patient = $this->patient();

        $this->putJson("/api/v1/patients/{$patient->id}/puce", [
            'puce' => '756 091 234',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('puce');
    }

    public function test_un_chien_puce_se_declare_a_amicus_une_seule_fois(): void
    {
        $patient = $this->patient(['espece' => 'CH', 'puce' => '756 091 234 567 890', 'amicus_declare_le' => null]);
        $entetes = $this->enTetes($this->jeton);

        $premiere = $this->postJson("/api/v1/patients/{$patient->id}/amicus", [], $entetes)->assertOk();
        $this->assertNotNull($premiere->json('data.amicus_declare_le'));

        // Idempotent : redéclarer ne change pas l'horodatage.
        $seconde = $this->postJson("/api/v1/patients/{$patient->id}/amicus", [], $entetes)->assertOk();
        $this->assertSame($premiere->json('data.amicus_declare_le'), $seconde->json('data.amicus_declare_le'));
    }

    public function test_la_declaration_amicus_exige_une_puce(): void
    {
        $patient = $this->patient(['espece' => 'CH', 'puce' => null]);

        $this->postJson("/api/v1/patients/{$patient->id}/amicus", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('puce');

        $this->assertNull($patient->fresh()->amicus_declare_le);
    }

    public function test_seuls_les_chiens_se_declarent_a_amicus(): void
    {
        $patient = $this->patient(['espece' => 'CT', 'puce' => '756 091 234 567 890']);

        $this->postJson("/api/v1/patients/{$patient->id}/amicus", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('espece');
    }

    /** @param array<string, mixed> $attributs */
    private function patient(array $attributs = []): Patient
    {
        return Patient::factory()->for($this->client)->create($attributs);
    }
}
