<?php

namespace Tests\Feature\Equipe;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Gestes RH (23.10.2026) : le droit `equipe` donné à tous ne suffit plus
 * pour se nommer administrateur. Gérer l'équipe est le droit `rh`, et
 * personne ne se donne ni ne donne plus de droits qu'il n'en a, ni ne
 * touche à un administrateur sans l'être.
 */
class EquipeDroitsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
    }

    public function test_un_veterinaire_ou_une_asv_ne_se_nomme_pas_administrateur(): void
    {
        foreach ([null, 'asv'] as $etat) {
            ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $etat);

            $this->patchJson("/api/v1/equipe/employes/{$employe->id}", ['role_id' => $this->role(RolesParDefaut::ADMINISTRATEUR)->id], $this->enTetes($jeton))
                ->assertForbidden();
            $this->assertNotSame($this->role(RolesParDefaut::ADMINISTRATEUR)->id, $employe->refresh()->role_id);
        }
    }

    public function test_une_asv_ne_suspend_ni_ne_licencie_l_administrateur(): void
    {
        ['employe' => $administrateur] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, [], 'asv');

        $this->postJson("/api/v1/equipe/employes/{$administrateur->id}/suspendre", [], $this->enTetes($jetonAsv))->assertForbidden();
        $this->postJson("/api/v1/equipe/employes/{$administrateur->id}/licencier", ['fin_contrat_le' => today()->addMonth()->toDateString()], $this->enTetes($jetonAsv))
            ->assertForbidden();
        $this->assertFalse($administrateur->refresh()->acces_suspendu);
    }

    public function test_un_responsable_rh_ne_donne_pas_un_role_plus_large_que_le_sien_ni_ne_touche_un_administrateur(): void
    {
        $responsable = Role::withoutGlobalScopes()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'nom' => 'Responsable RH',
            'domaines' => array_values(array_filter(DomainePermission::cases(), fn ($d) => $d !== DomainePermission::Organisation)),
        ]);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_id' => $responsable->id]);
        ['employe' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        ['employe' => $administrateur] = $this->administrateurAvecJeton($this->clinique);

        $this->patchJson("/api/v1/equipe/employes/{$asv->id}", ['role_id' => $this->role(RolesParDefaut::ADMINISTRATEUR)->id], $this->enTetes($jeton))
            ->assertForbidden();
        $this->patchJson("/api/v1/equipe/employes/{$asv->id}", ['role_id' => $this->role(RolesParDefaut::VETERINAIRE)->id], $this->enTetes($jeton))
            ->assertOk();
        $this->patchJson("/api/v1/equipe/employes/{$administrateur->id}", ['tel' => '079 000 00 00'], $this->enTetes($jeton))
            ->assertForbidden();
        $this->postJson("/api/v1/equipe/employes/{$administrateur->id}/suspendre", [], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_personne_ne_change_son_propre_role_ni_ne_se_suspend(): void
    {
        ['employe' => $administrateur, 'jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);

        $this->patchJson("/api/v1/equipe/employes/{$administrateur->id}", ['role_id' => $this->role(RolesParDefaut::VETERINAIRE)->id], $this->enTetes($jeton))
            ->assertForbidden();
        $this->postJson("/api/v1/equipe/employes/{$administrateur->id}/suspendre", [], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_le_salaire_ne_se_voit_qu_avec_la_gestion_de_l_equipe(): void
    {
        ['employe' => $employe, 'jeton' => $jetonVeterinaire] = $this->employeAvecJeton($this->clinique, ['salaire_mensuel' => 8200]);
        ['jeton' => $jetonAdministrateur] = $this->administrateurAvecJeton($this->clinique);

        $this->getJson("/api/v1/equipe/employes/{$employe->id}", $this->enTetes($jetonVeterinaire))
            ->assertOk()->assertJsonMissingPath('data.salaire_mensuel');
        $this->getJson("/api/v1/equipe/employes/{$employe->id}", $this->enTetes($jetonAdministrateur))
            ->assertOk()->assertJsonPath('data.salaire_mensuel', 8200);
    }

    private function role(string $nom): Role
    {
        return Role::withoutGlobalScopes()
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', $nom)
            ->firstOrFail();
    }
}
