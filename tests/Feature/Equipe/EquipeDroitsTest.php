<?php

namespace Tests\Feature\Equipe;

use App\Auth\CalculateurPermissions;
use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
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
        $this->postJson("/api/v1/equipe/employes/{$administrateur->id}/licencier", ['fin_contrat_le' => aujourdhui()->addMonth()->toDateString()], $this->enTetes($jetonAsv))
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

    /** Licencié avec une fin de contrat à venir : il ne compte plus comme administrateur qui reste. */
    public function test_un_administrateur_licencie_ne_garde_pas_l_organisation(): void
    {
        ['employe' => $a, 'jeton' => $jetonA] = $this->administrateurAvecJeton($this->clinique);
        ['employe' => $b, 'jeton' => $jetonB] = $this->administrateurAvecJeton($this->clinique);

        $this->postJson("/api/v1/equipe/employes/{$b->id}/licencier", ['fin_contrat_le' => aujourdhui()->addMonth()->toDateString()], $this->enTetes($jetonA))->assertOk();
        // B, sur le départ, ne licencie pas A : il ne resterait personne
        $this->postJson("/api/v1/equipe/employes/{$a->id}/licencier", ['fin_contrat_le' => aujourdhui()->toDateString()], $this->enTetes($jetonB))->assertForbidden();
        $this->postJson("/api/v1/equipe/employes/{$a->id}/suspendre", [], $this->enTetes($jetonB))->assertForbidden();
        $this->assertNull($a->refresh()->licenciement_notifie_le);
    }

    /** Rétablir quelqu'un qui a un droit de plus que soi (une exception accordée), non : même sans être administrateur, il est « plus haut placé » (24.10.2026). */
    public function test_on_ne_retablit_pas_quelqu_un_qui_a_un_droit_de_plus(): void
    {
        $gestion = Role::withoutGlobalScopes()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'nom' => 'Gestion d’équipe',
            'domaines' => [DomainePermission::Equipe, DomainePermission::Rh, DomainePermission::Planning],
        ]);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_id' => $gestion->id]);
        $asv = fn () => $this->employeAvecJeton($this->clinique, [], 'asv')['employe'];

        $avecExtra = $asv();
        DerogationPermission::create(['employe_id' => $avecExtra->id, 'domaine' => DomainePermission::Rembourser, 'est_accordee' => true]);
        $avecExtra->update(['acces_suspendu' => true]);
        $this->postJson("/api/v1/equipe/employes/{$avecExtra->id}/retablir", [], $this->enTetes($jeton))->assertForbidden();
        $this->assertTrue($avecExtra->refresh()->acces_suspendu);

        $sansExtra = $asv();
        $sansExtra->update(['acces_suspendu' => true]);
        $droitsAsv = app(CalculateurPermissions::class)->pour($sansExtra->load('role'));
        $gestion->update(['domaines' => array_values(array_unique([...array_map(fn (DomainePermission $d) => $d->value, $gestion->domaines->all()), ...$droitsAsv]))]);
        $this->postJson("/api/v1/equipe/employes/{$sansExtra->id}/retablir", [], $this->enTetes($jeton))->assertOk();
        $this->assertFalse($sansExtra->refresh()->acces_suspendu);
    }

    /** Ni rétablir son accès, ni annuler son licenciement, ni régler son contrat sans être administrateur. */
    public function test_personne_ne_se_rend_son_acces_ni_ne_regle_son_contrat(): void
    {
        $responsable = Role::withoutGlobalScopes()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'nom' => 'Responsable RH',
            'domaines' => array_values(array_filter(DomainePermission::cases(), fn ($d) => $d !== DomainePermission::Organisation)),
        ]);
        ['employe' => $rh, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_id' => $responsable->id, 'salaire_mensuel' => 5000]);
        $rh->update(['licenciement_notifie_le' => aujourdhui(), 'fin_contrat_le' => aujourdhui()->addMonth()]);

        $this->postJson("/api/v1/equipe/employes/{$rh->id}/annuler-licenciement", [], $this->enTetes($jeton))->assertForbidden();
        $this->postJson("/api/v1/equipe/employes/{$rh->id}/retablir", [], $this->enTetes($jeton))->assertForbidden();
        $this->patchJson("/api/v1/equipe/employes/{$rh->id}", ['salaire_mensuel' => 9000], $this->enTetes($jeton))->assertForbidden();
        $this->patchJson("/api/v1/equipe/employes/{$rh->id}", ['tel' => '079 111 11 11'], $this->enTetes($jeton))->assertOk();
        $this->assertNotNull($rh->refresh()->licenciement_notifie_le);
        $this->assertEquals(5000, $rh->salaire_mensuel);

        // Ni rendre l'accès à quelqu'un qui a plus de droits que soi
        ['employe' => $administrateur] = $this->administrateurAvecJeton($this->clinique);
        $administrateur->update(['acces_suspendu' => true]);
        $this->postJson("/api/v1/equipe/employes/{$administrateur->id}/retablir", [], $this->enTetes($jeton))->assertForbidden();
    }

    private function role(string $nom): Role
    {
        return Role::withoutGlobalScopes()
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', $nom)
            ->firstOrFail();
    }
}
