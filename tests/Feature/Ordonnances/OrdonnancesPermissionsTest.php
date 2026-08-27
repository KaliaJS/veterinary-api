<?php

namespace Tests\Feature\Ordonnances;

use App\Enums\DomainePermission;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le registre des ordonnances relève du domaine patients (dossier
 * médical) — une ASV y accède, contrairement à la facturation. Sans le
 * domaine, chaque endpoint refuse en 403.
 */
class OrdonnancesPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    private Produit $medicament;

    private Ordonnance $ordonnance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $organisation = $this->clinique->societe->organisation_id;

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $organisation]))
            ->create();
        $this->medicament = Produit::factory()->medicament()->create(['organisation_id' => $organisation]);
        $this->ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'renouvellements' => 1,
        ]);
    }

    public function test_chaque_endpoint_du_registre_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        $charges = [
            ['getJson', '/api/v1/ordonnances', []],
            ['postJson', '/api/v1/ordonnances', ['patient_id' => $this->patient->id, 'lignes' => [['produit_id' => $this->medicament->id, 'posologie' => 'Refusé']]]],
            ['getJson', "/api/v1/ordonnances/{$this->ordonnance->id}", []],
            ['deleteJson', "/api/v1/ordonnances/{$this->ordonnance->id}", []],
            ['postJson', "/api/v1/ordonnances/{$this->ordonnance->id}/renouveler", []],
            ['postJson', "/api/v1/ordonnances/{$this->ordonnance->id}/cloturer", []],
        ];

        foreach ($charges as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_une_derogation_retirant_le_domaine_patients_ferme_le_registre(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Patients,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/ordonnances', $this->enTetes($jeton))->assertForbidden();

        // Le domaine stocks, lui, reste ouvert.
        $this->getJson('/api/v1/stocks/produits', $this->enTetes($jeton))->assertOk();
    }

    public function test_un_role_avec_le_domaine_patients_accede_au_registre(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/ordonnances', $this->enTetes($jeton))->assertOk();
        $this->getJson("/api/v1/ordonnances/{$this->ordonnance->id}", $this->enTetes($jeton))->assertOk();
    }
}
