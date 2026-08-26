<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\ReleveOphtalmologique;
use App\Models\RendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Deux niveaux d'isolation. Niveau ORGANISATION : clients et patients
 * d'une autre organisation sont introuvables (404) et irréférençables
 * (422) — mais PARTAGÉS entre les cliniques d'une même organisation,
 * comme tout le dossier médical, compte rendu SOAP compris.
 */
class PatientsIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $cliniqueA;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueB;

    private Client $clientA;

    private Client $clientB;

    private Patient $patientA;

    private Patient $patientB;

    private Pesee $peseeB;

    private ReleveOphtalmologique $releveB;

    private RendezVous $rendezVousSoeur;

    private RendezVous $rendezVousB;

    private string $jetonA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliniqueA = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->cliniqueA->societe_id]);
        $this->cliniqueB = Clinique::factory()->create();

        $this->clientA = Client::factory()->create([
            'organisation_id' => $this->cliniqueA->societe->organisation_id,
        ]);
        $this->patientA = Patient::factory()->for($this->clientA)->create();

        $this->clientB = Client::factory()->create([
            'organisation_id' => $this->cliniqueB->societe->organisation_id,
        ]);
        $this->patientB = Patient::factory()->for($this->clientB)->create();
        $this->peseeB = Pesee::factory()->for($this->patientB)->create();
        $this->releveB = ReleveOphtalmologique::factory()->for($this->patientB)->create();

        $this->rendezVousSoeur = $this->rendezVousDans($this->cliniqueSoeur);
        $this->rendezVousB = $this->rendezVousDans($this->cliniqueB);
        CompteRendu::factory()->create(['rendez_vous_id' => $this->rendezVousB->id]);

        ['jeton' => $this->jetonA] = $this->employeAvecJeton($this->cliniqueA);
    }

    public function test_les_listes_ne_montrent_que_l_organisation_du_jeton(): void
    {
        $entetes = $this->enTetes($this->jetonA);

        $clients = $this->getJson('/api/v1/clients', $entetes)->assertOk();
        $this->assertSame([$this->clientA->id], array_column($clients->json('data'), 'id'));

        $patients = $this->getJson('/api/v1/patients', $entetes)->assertOk();
        $this->assertSame([$this->patientA->id], array_column($patients->json('data'), 'id'));
    }

    public function test_toute_ressource_d_une_autre_organisation_repond_introuvable(): void
    {
        $clientB = $this->clientB->id;
        $patientB = $this->patientB->id;

        $appels = [
            ['getJson', "/api/v1/clients/{$clientB}", []],
            ['patchJson', "/api/v1/clients/{$clientB}", ['nom' => 'Piraté']],
            ['postJson', "/api/v1/clients/{$clientB}/compte", []],
            ['deleteJson', "/api/v1/clients/{$clientB}/compte", []],
            ['getJson', "/api/v1/patients/{$patientB}", []],
            ['patchJson', "/api/v1/patients/{$patientB}", ['nom' => 'Piraté']],
            ['putJson', "/api/v1/patients/{$patientB}/puce", ['puce' => '756091234567890']],
            ['postJson', "/api/v1/patients/{$patientB}/amicus", []],
            ['postJson', "/api/v1/patients/{$patientB}/pesees", ['poids_kg' => 10]],
            ['patchJson', "/api/v1/patients/{$patientB}/pesees/{$this->peseeB->id}", ['poids_kg' => 10]],
            ['deleteJson', "/api/v1/patients/{$patientB}/pesees/{$this->peseeB->id}", []],
            ['getJson', "/api/v1/patients/{$patientB}/vaccinations", []],
            ['postJson', "/api/v1/patients/{$patientB}/vaccinations", ['nom' => 'Rage', 'echeance_le' => today()->addYear()->toDateString()]],
            ['getJson', "/api/v1/patients/{$patientB}/visites", []],
            ['postJson', "/api/v1/patients/{$patientB}/visites", ['motif' => 'Intrusion']],
            ['getJson', "/api/v1/patients/{$patientB}/documents", []],
            ['postJson', "/api/v1/patients/{$patientB}/documents", ['nom' => 'x.pdf']],
            ['getJson', "/api/v1/patients/{$patientB}/releves-ophtalmologiques", []],
            ['postJson', "/api/v1/patients/{$patientB}/releves-ophtalmologiques", []],
            ['patchJson', "/api/v1/patients/{$patientB}/releves-ophtalmologiques/{$this->releveB->id}", []],
            ['deleteJson', "/api/v1/patients/{$patientB}/releves-ophtalmologiques/{$this->releveB->id}", []],
            ['getJson', "/api/v1/patients/{$patientB}/dents", []],
            ['putJson', "/api/v1/patients/{$patientB}/dents", ['dents' => []]],
        ];

        foreach ($appels as [$methode, $uri, $charge]) {
            $this->appeler($methode, $uri, $charge)->assertNotFound();
        }

        // Rien n'a bougé côté B.
        $this->assertNotSame('Piraté', Client::withoutGlobalScopes()->find($this->clientB->id)->nom);
        $this->assertNotSame('Piraté', Patient::withoutGlobalScopes()->find($this->patientB->id)->nom);
        $this->assertDatabaseHas('pesees', ['id' => $this->peseeB->id]);
    }

    public function test_une_sous_ressource_d_un_autre_patient_est_introuvable(): void
    {
        // La pesée B accrochée au patient A (même organisation que le
        // jeton) : la résolution passe par le patient, donc 404.
        $this->patchJson(
            "/api/v1/patients/{$this->patientA->id}/pesees/{$this->peseeB->id}",
            ['poids_kg' => 5],
            $this->enTetes($this->jetonA),
        )->assertNotFound();

        $this->deleteJson(
            "/api/v1/patients/{$this->patientA->id}/releves-ophtalmologiques/{$this->releveB->id}",
            [],
            $this->enTetes($this->jetonA),
        )->assertNotFound();
    }

    public function test_referencer_un_client_d_une_autre_organisation_est_refuse(): void
    {
        $this->postJson('/api/v1/patients', [
            'client_id' => $this->clientB->id,
            'nom' => 'Passager',
            'espece' => 'CH',
            'sexe' => 'Mâle',
        ], $this->enTetes($this->jetonA))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');
    }

    public function test_une_creation_est_rattachee_a_l_organisation_du_jeton_meme_forcee(): void
    {
        $reponse = $this->postJson('/api/v1/clients', [
            'prenom' => 'Nora',
            'nom' => 'Klein',
            'organisation_id' => $this->cliniqueB->societe->organisation_id,
        ], $this->enTetes($this->jetonA))->assertCreated();

        $this->assertSame(
            $this->cliniqueA->societe->organisation_id,
            Client::withoutGlobalScopes()->find($reponse->json('data.id'))->organisation_id,
        );
    }

    public function test_patients_et_clients_sont_partages_entre_cliniques_de_la_meme_organisation(): void
    {
        ['jeton' => $jetonSoeur] = $this->employeAvecJeton($this->cliniqueSoeur);
        $entetes = $this->enTetes($jetonSoeur);

        $this->getJson("/api/v1/clients/{$this->clientA->id}", $entetes)->assertOk();
        $this->getJson("/api/v1/patients/{$this->patientA->id}", $entetes)->assertOk();

        // Le dossier se complète depuis n'importe quelle clinique de
        // l'organisation — patients de niveau organisation.
        $this->postJson("/api/v1/patients/{$this->patientA->id}/pesees", ['poids_kg' => 7.2], $entetes)
            ->assertCreated();
    }

    public function test_le_compte_rendu_est_de_niveau_organisation_comme_le_dossier(): void
    {
        $entetes = $this->enTetes($this->jetonA);

        // Rendez-vous d'une autre organisation : introuvable, rien créé.
        $this->getJson("/api/v1/rendez-vous/{$this->rendezVousB->id}/compte-rendu", $entetes)->assertNotFound();
        $this->putJson("/api/v1/rendez-vous/{$this->rendezVousB->id}/compte-rendu", ['s' => 'x'], $entetes)->assertNotFound();
        $this->assertSame(1, CompteRendu::count());

        // Rendez-vous d'une clinique SŒUR (même organisation) : le
        // dossier médical est partagé — le compte rendu se rédige et
        // se lit depuis n'importe quelle clinique de l'organisation.
        $this->putJson(
            "/api/v1/rendez-vous/{$this->rendezVousSoeur->id}/compte-rendu",
            ['s' => 'Consultation dans la clinique sœur.'],
            $entetes,
        )->assertCreated();

        $this->getJson("/api/v1/rendez-vous/{$this->rendezVousSoeur->id}/compte-rendu", $entetes)
            ->assertOk()
            ->assertJsonPath('data.s', 'Consultation dans la clinique sœur.');

        $this->assertSame(2, CompteRendu::count());
    }

    private function rendezVousDans(Clinique $clinique): RendezVous
    {
        return RendezVous::factory()->create([
            'clinique_id' => $clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $clinique->id])->id,
        ]);
    }

    private function appeler(string $methode, string $uri, array $charge): TestResponse
    {
        $entetes = $this->enTetes($this->jetonA);

        return $methode === 'getJson'
            ? $this->getJson($uri, $entetes)
            : $this->{$methode}($uri, $charge, $entetes);
    }
}
