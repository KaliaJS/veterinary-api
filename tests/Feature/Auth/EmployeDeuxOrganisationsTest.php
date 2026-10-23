<?php

namespace Tests\Feature\Auth;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Salle;
use App\Models\User;
use Database\Factories\SiteWebFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Un même compte employé dans deux organisations (une fiche par
 * organisation, rôles différents) : avec le jeton d'une clinique, il ne
 * voit, n'écrit et ne peut que ce que sa fiche de CETTE organisation lui
 * ouvre. Changer de clinique réémet le jeton vers l'autre organisation.
 */
class EmployeDeuxOrganisationsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private User $user;

    private Clinique $cliniqueA;

    private Clinique $cliniqueB;

    private Employe $ficheA;

    private Employe $ficheB;

    private Patient $patientA;

    private Patient $patientB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliniqueA = Clinique::factory()->create(['nom' => 'Clinique des Deux Rives']);
        $this->cliniqueB = Clinique::factory()->create(['nom' => 'Clinique du Lac']);
        $this->user = User::factory()->create();

        // Assistant chez A (ni caisse ni administration), administrateur chez B
        $this->ficheA = Employe::factory()->dans($this->cliniqueA)->assistant()->create(['user_id' => $this->user->id]);
        $this->ficheB = Employe::factory()->dans($this->cliniqueB)->administrateur()->create(['user_id' => $this->user->id]);

        $this->patientA = $this->patient($this->cliniqueA, 'Pacha');
        $this->patientB = $this->patient($this->cliniqueB, 'Filou');
    }

    public function test_le_jeton_d_une_clinique_ne_montre_que_les_donnees_de_son_organisation(): void
    {
        Salle::factory()->create(['clinique_id' => $this->cliniqueA->id]);
        $salleB = Salle::factory()->create(['clinique_id' => $this->cliniqueB->id]);
        SiteWebFactory::new()->create(['organisation_id' => $this->cliniqueA->societe->organisation_id]);
        $siteB = SiteWebFactory::new()->create(['organisation_id' => $this->cliniqueB->societe->organisation_id]);
        // Le jeton de B, où la fiche est administratrice : tout lui est ouvert, mais dans B seulement
        $entetes = $this->enTetes($this->jeton($this->cliniqueB));

        $this->getJson('/api/v1/patients', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->patientB->id);
        $this->getJson("/api/v1/patients/{$this->patientA->id}", $entetes)->assertNotFound();
        $this->getJson('/api/v1/salles', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $salleB->id);
        $this->getJson('/api/v1/cliniques', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->cliniqueB->id);
        $this->getJson('/api/v1/site', $entetes)
            ->assertOk()
            ->assertJsonPath('data.slug', $siteB->slug);
    }

    public function test_les_droits_sont_ceux_de_la_fiche_de_l_organisation_du_jeton(): void
    {
        $factureB = Facture::factory()->create(['clinique_id' => $this->cliniqueB->id]);
        Facture::factory()->create(['clinique_id' => $this->cliniqueA->id]);
        $jetonA = $this->jeton($this->cliniqueA);
        $jetonB = $this->jeton($this->cliniqueB);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jetonA))
            ->assertOk()
            ->assertJsonPath('data.employe.id', $this->ficheA->id)
            ->assertJsonPath('data.clinique.id', $this->cliniqueA->id)
            ->assertJsonPath('data.permissions', ['planning', 'patients', 'stocks', 'equipe']);
        $this->getJson('/api/v1/facturation/factures', $this->enTetes($jetonA))->assertForbidden();
        $this->getJson('/api/v1/organisation', $this->enTetes($jetonA))->assertForbidden();

        // Administrateur chez B : la caisse et l'organisation de B, et elles seules
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jetonB))
            ->assertOk()
            ->assertJsonPath('data.employe.id', $this->ficheB->id);
        $this->getJson('/api/v1/facturation/factures', $this->enTetes($jetonB))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $factureB->id);
        $this->getJson('/api/v1/organisation', $this->enTetes($jetonB))
            ->assertOk()
            ->assertJsonPath('data.id', $this->cliniqueB->societe->organisation_id);
    }

    public function test_l_equipe_et_les_ecritures_restent_dans_l_organisation_du_jeton(): void
    {
        $jetonB = $this->jeton($this->cliniqueB);

        $this->getJson('/api/v1/equipe/employes', $this->enTetes($jetonB))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->ficheB->id);
        $this->getJson("/api/v1/equipe/employes/{$this->ficheA->id}", $this->enTetes($jetonB))->assertNotFound();

        $id = $this->postJson('/api/v1/clients', [
            'civilite' => 'Mme',
            'nom' => 'Meyer',
            'prenom' => 'Anne',
            'tel' => '079 123 45 67',
        ], $this->enTetes($jetonB))->assertCreated()->json('data.id');

        $this->assertSame($this->cliniqueB->societe->organisation_id, Client::withoutGlobalScopes()->findOrFail($id)->organisation_id);
    }

    public function test_changer_de_clinique_passe_d_une_organisation_a_l_autre(): void
    {
        $jetonA = $this->jeton($this->cliniqueA);

        $jetonB = $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $this->cliniqueB->id], $this->enTetes($jetonA))
            ->assertOk()
            ->assertJsonPath('data.employe.id', $this->ficheB->id)
            ->assertJsonPath('data.clinique.id', $this->cliniqueB->id)
            ->assertJsonCount(2, 'data.cliniques')
            ->json('data.token');

        $this->getJson('/api/v1/patients', $this->enTetes($jetonB))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->patientB->id);
        // L'ancien jeton est révoqué
        $this->getJson('/api/v1/patients', $this->enTetes($jetonA))->assertUnauthorized();
    }

    private function jeton(Clinique $clinique): string
    {
        app(ContexteClinique::class)->oublier();

        return app(EmetteurJetons::class)->emettre($this->user, $clinique->id)['jeton']->plainTextToken;
    }

    private function patient(Clinique $clinique, string $nom): Patient
    {
        return Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]))
            ->create(['nom' => $nom]);
    }
}
