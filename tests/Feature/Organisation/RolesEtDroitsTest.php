<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Rôles et droits réglables par l'organisation (23.10.2026) : les défauts
 * ne sont qu'un point de départ, chaque rôle se règle droit par droit,
 * chaque personne peut avoir des exceptions, et l'organisation garde
 * toujours un administrateur actif.
 */
class RolesEtDroitsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $administrateur;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['employe' => $this->administrateur, 'jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    public function test_les_roles_par_defaut_et_le_catalogue_des_droits(): void
    {
        $this->getJson('/api/v1/organisation/roles', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonCount(count(DomainePermission::cases()), 'droits')
            ->assertJsonPath('droits.7.valeur', 'rh')
            ->assertJsonPath('droits.7.libelle', 'Gérer l’équipe');
    }

    public function test_un_role_cree_puis_donne_ouvre_exactement_ses_droits(): void
    {
        $id = $this->postJson('/api/v1/organisation/roles', ['nom' => 'Réception', 'droits' => ['planning', 'facturation']], $this->enTetes($this->jeton))
            ->assertCreated()->assertJsonPath('data.droits', ['planning', 'facturation'])->json('data.id');

        ['employe' => $receptionniste, 'jeton' => $jetonReception] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->patchJson("/api/v1/equipe/employes/{$receptionniste->id}", ['role_id' => $id], $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/facturation/factures', $this->enTetes($jetonReception))->assertOk();
        $this->getJson('/api/v1/patients', $this->enTetes($jetonReception))->assertForbidden();

        // Le rôle se règle : la caisse retirée, l'accès se ferme aussitôt
        $this->putJson("/api/v1/organisation/roles/{$id}", ['droits' => ['planning']], $this->enTetes($this->jeton))->assertOk();
        $this->getJson('/api/v1/facturation/factures', $this->enTetes($jetonReception))->assertForbidden();
    }

    public function test_l_organisation_garde_toujours_un_administrateur_actif(): void
    {
        $administrateur = $this->role(RolesParDefaut::ADMINISTRATEUR);

        $this->putJson("/api/v1/organisation/roles/{$administrateur->id}", ['droits' => ['planning', 'patients']], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('droits');
        $this->assertTrue($administrateur->refresh()->domaines->contains(DomainePermission::Organisation));

        $this->putJson("/api/v1/organisation/employes/{$this->administrateur->id}/exceptions", [
            'droits' => [['droit' => 'organisation', 'accorde' => false]],
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors(['droits' => 'L’organisation doit garder au moins un administrateur actif (droit Administration).']);
    }

    public function test_une_exception_accorde_un_droit_au_dela_du_role(): void
    {
        ['employe' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');

        $this->putJson("/api/v1/organisation/employes/{$asv->id}/exceptions", [
            'droits' => [['droit' => 'prescrire', 'accorde' => true], ['droit' => 'bloc', 'accorde' => false]],
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data.droits')
            ->assertJsonPath('data.effectifs', fn (array $droits): bool => in_array('prescrire', $droits, true) && ! in_array('bloc', $droits, true));

        $this->getJson('/api/v1/organisation/roles', $this->enTetes($this->jeton))
            ->assertJsonPath('exceptions.0.employe_id', $asv->id);
    }

    public function test_un_role_porte_par_quelqu_un_ne_se_supprime_pas(): void
    {
        $this->deleteJson('/api/v1/organisation/roles/'.$this->role(RolesParDefaut::ADMINISTRATEUR)->id, [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        $vide = $this->postJson('/api/v1/organisation/roles', ['nom' => 'Stagiaire', 'droits' => []], $this->enTetes($this->jeton))->json('data.id');
        $this->deleteJson("/api/v1/organisation/roles/{$vide}", [], $this->enTetes($this->jeton))->assertNoContent();
    }

    public function test_les_roles_d_une_autre_organisation_et_les_non_administrateurs(): void
    {
        $etrangere = Clinique::factory()->create();
        $this->administrateurAvecJeton($etrangere);
        $roleEtranger = Role::withoutGlobalScopes()->where('organisation_id', $etrangere->societe->organisation_id)->firstOrFail();

        $this->putJson("/api/v1/organisation/roles/{$roleEtranger->id}", ['nom' => 'Pris'], $this->enTetes($this->jeton))->assertNotFound();

        ['jeton' => $jetonVeterinaire] = $this->employeAvecJeton($this->clinique);
        $this->getJson('/api/v1/organisation/roles', $this->enTetes($jetonVeterinaire))->assertForbidden();
    }

    private function role(string $nom): Role
    {
        return Role::withoutGlobalScopes()
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', $nom)
            ->firstOrFail();
    }
}
