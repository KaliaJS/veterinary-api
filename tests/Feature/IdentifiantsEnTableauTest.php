<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Un identifiant envoyé en tableau (`client_id[]=3`) est refusé par la
 * validation (422), jamais accepté par `exists` pour finir en erreur 500
 * dans le contrôleur (24.10.2026).
 */
class IdentifiantsEnTableauTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_un_identifiant_en_tableau_est_refuse_proprement(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $entetes = $this->enTetes($jeton);
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
        $patient = Patient::factory()->create(['client_id' => $client->id]);

        $this->postJson('/api/v1/patients', ['client_id' => [$client->id], 'nom' => 'Rex', 'espece' => 'CH', 'sexe' => 'Mâle'], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
        $this->postJson('/api/v1/facturation/factures', ['patient_id' => [$patient->id]], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);
        $this->postJson('/api/v1/facturation/devis', ['patient_id' => [$patient->id]], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);

        // L'identifiant tel quel passe toujours
        $this->postJson('/api/v1/patients', ['client_id' => $client->id, 'nom' => 'Rex', 'espece' => 'CH', 'sexe' => 'Mâle'], $entetes)
            ->assertCreated();
    }
}
