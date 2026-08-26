<?php

namespace Tests\Feature\Patients;

use App\Enums\DomainePermission;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\ReleveOphtalmologique;
use App\Models\RendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Permissions du domaine patients, vérifiées en base à chaque
 * requête : sans le domaine, chaque endpoint — fiches, dossier
 * médical, carnet, compte espace client, compte rendu SOAP — refuse
 * en 403.
 */
class PatientsPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Client $client;

    private Patient $patient;

    private Pesee $pesee;

    private ReleveOphtalmologique $releve;

    private RendezVous $rendezVous;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->client = Client::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);
        $this->patient = Patient::factory()->for($this->client)->create([
            'espece' => 'CH',
            'puce' => '756 091 234 567 890',
        ]);
        $this->pesee = Pesee::factory()->for($this->patient)->create();
        $this->releve = ReleveOphtalmologique::factory()->for($this->patient)->create();
        $this->rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
        ]);
    }

    public function test_chaque_endpoint_du_domaine_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        foreach ($this->appelsDuDomaine() as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_une_derogation_retirant_le_domaine_patients_ferme_le_domaine(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Patients,
            'est_accordee' => false,
        ]);

        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/patients', $entetes)->assertForbidden();
        $this->getJson('/api/v1/clients', $entetes)->assertForbidden();
        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu", $entetes)->assertForbidden();

        // Le domaine planning, lui, reste ouvert.
        $this->getJson('/api/v1/rendez-vous', $entetes)->assertOk();
    }

    public function test_un_role_avec_le_domaine_patients_accede_au_domaine(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/patients', $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/clients', $this->enTetes($jeton))->assertOk();
        $this->getJson("/api/v1/patients/{$this->patient->id}", $this->enTetes($jeton))->assertOk();
    }

    /**
     * Chaque endpoint du domaine avec une charge qui passe la
     * validation : l'autorisation vient après la FormRequest, une
     * charge invalide répondrait 422 avant le 403.
     *
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function appelsDuDomaine(): array
    {
        $client = $this->client->id;
        $patient = $this->patient->id;

        return [
            ['getJson', '/api/v1/clients', []],
            ['postJson', '/api/v1/clients', ['prenom' => 'Test', 'nom' => 'Refusé']],
            ['getJson', "/api/v1/clients/{$client}", []],
            ['patchJson', "/api/v1/clients/{$client}", []],
            ['postJson', "/api/v1/clients/{$client}/compte", []],
            ['deleteJson', "/api/v1/clients/{$client}/compte", []],
            ['getJson', '/api/v1/patients', []],
            ['postJson', '/api/v1/patients', ['client_id' => $client, 'nom' => 'Refusé', 'espece' => 'CH', 'sexe' => 'Mâle']],
            ['getJson', "/api/v1/patients/{$patient}", []],
            ['patchJson', "/api/v1/patients/{$patient}", []],
            ['putJson', "/api/v1/patients/{$patient}/puce", ['puce' => '756091234567890']],
            ['postJson', "/api/v1/patients/{$patient}/amicus", []],
            ['postJson', "/api/v1/patients/{$patient}/pesees", ['poids_kg' => 8.5]],
            ['patchJson', "/api/v1/patients/{$patient}/pesees/{$this->pesee->id}", ['poids_kg' => 8.5]],
            ['deleteJson', "/api/v1/patients/{$patient}/pesees/{$this->pesee->id}", []],
            ['getJson', "/api/v1/patients/{$patient}/vaccinations", []],
            ['postJson', "/api/v1/patients/{$patient}/vaccinations", ['nom' => 'Rage', 'echeance_le' => today()->addYear()->toDateString()]],
            ['getJson', "/api/v1/patients/{$patient}/visites", []],
            ['postJson', "/api/v1/patients/{$patient}/visites", ['motif' => 'Contrôle']],
            ['getJson', "/api/v1/patients/{$patient}/documents", []],
            ['postJson', "/api/v1/patients/{$patient}/documents", ['nom' => 'x.pdf']],
            ['getJson', "/api/v1/patients/{$patient}/releves-ophtalmologiques", []],
            ['postJson', "/api/v1/patients/{$patient}/releves-ophtalmologiques", []],
            ['patchJson', "/api/v1/patients/{$patient}/releves-ophtalmologiques/{$this->releve->id}", []],
            ['deleteJson', "/api/v1/patients/{$patient}/releves-ophtalmologiques/{$this->releve->id}", []],
            ['getJson', "/api/v1/patients/{$patient}/dents", []],
            ['putJson', "/api/v1/patients/{$patient}/dents", ['dents' => []]],
            ['getJson', "/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu", []],
            ['putJson', "/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu", ['s' => 'Refusé']],
        ];
    }
}
