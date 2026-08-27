<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\OrganisationFacture;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les modèles de structure n'ont pas de scope global : les contrôleurs
 * du domaine résolvent TOUJOURS depuis l'organisation du jeton — une
 * autre organisation reste introuvable.
 */
class OrganisationIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueEtrangere;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);
    }

    public function test_l_organisation_affichee_est_celle_du_jeton(): void
    {
        $this->getJson('/api/v1/organisation', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $this->clinique->societe->organisation_id);
    }

    public function test_les_cliniques_listees_sont_celles_de_l_organisation(): void
    {
        $this->getJson('/api/v1/organisation/cliniques', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->clinique->id);
    }

    public function test_une_clinique_d_une_autre_organisation_est_introuvable(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $this->patchJson("/api/v1/organisation/cliniques/{$this->cliniqueEtrangere->id}", ['nom' => 'Piratée'], $entetes)
            ->assertNotFound();

        $this->deleteJson("/api/v1/organisation/cliniques/{$this->cliniqueEtrangere->id}", [], $entetes)
            ->assertNotFound();
    }

    public function test_une_clinique_ne_se_rattache_pas_a_une_societe_etrangere(): void
    {
        $this->patchJson("/api/v1/organisation/cliniques/{$this->clinique->id}", [
            'societe_id' => $this->cliniqueEtrangere->societe_id,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('societe_id');
    }

    public function test_les_factures_d_abonnement_sont_celles_de_l_organisation(): void
    {
        OrganisationFacture::factory()->create([
            'organisation_id' => $this->cliniqueEtrangere->societe->organisation_id,
        ]);
        $notre = OrganisationFacture::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);

        $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero', $notre->numero);
    }
}
