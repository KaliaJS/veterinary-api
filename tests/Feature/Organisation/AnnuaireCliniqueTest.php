<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Annuaire des cliniques de l'organisation (id, nom, boxes) : ouvert à
 * tout employé sans le domaine `organisation` — pour nommer les
 * colonnes de la ventilation des stocks —, sans données de gestion,
 * borné à l'organisation du jeton.
 */
class AnnuaireCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['nom' => 'Clinique des Deux Rives', 'nombre_boxes' => 6]);
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'nom' => 'Clinique du Lac', 'nombre_boxes' => 4]);
        Clinique::factory()->create(['nom' => 'Clinique étrangère']);
    }

    public function test_l_annuaire_liste_les_cliniques_de_l_organisation_sans_le_domaine_organisation(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ASV)
                ->value('id'),
        ]);

        $this->getJson('/api/v1/organisation/cliniques', $this->enTetes($jeton))->assertForbidden();

        $reponse = $this->getJson('/api/v1/cliniques', $this->enTetes($jeton))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $this->clinique->id, 'nom' => 'Clinique des Deux Rives', 'nombre_boxes' => 6],
                ['id' => $this->cliniqueSoeur->id, 'nom' => 'Clinique du Lac', 'nombre_boxes' => 4],
            ]]);

        $this->assertStringNotContainsString('étrangère', $reponse->getContent());
    }

    public function test_l_annuaire_exige_un_jeton(): void
    {
        $this->getJson('/api/v1/cliniques')->assertUnauthorized();
    }
}
