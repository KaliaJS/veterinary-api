<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'assurance de l'animal (décision de Jim, 28.10.2026 : « un champ
 * Assurance sur la fiche de l'animal ») : enregistrée sur sa fiche, elle
 * dit le règlement de ses rendez-vous.
 */
class AssurancePatientTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_la_fiche_de_l_animal_enregistre_son_assurance(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique, [], 'asv');
        $organisation = $clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation]);

        $id = $this->postJson('/api/v1/patients', ['client_id' => $client->id, 'nom' => 'Félix', 'espece' => 'CT', 'sexe' => 'Mâle', 'assurance' => 'Epona'], $this->enTetes($jeton))
            ->assertCreated()->assertJsonPath('data.assurance', 'Epona')->json('data.id');

        $this->patchJson("/api/v1/patients/{$id}", ['assurance' => 'Animalia'], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.assurance', 'Animalia');
        $this->patchJson("/api/v1/patients/{$id}", ['assurance' => null], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.assurance', null);
        $this->assertNull(Patient::withoutGlobalScopes()->findOrFail($id)->assurance);
    }
}
