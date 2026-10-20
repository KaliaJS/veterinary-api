<?php

namespace Tests\Feature\Auth;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Permissions vérifiées EN BASE à chaque requête (Gates + Policies) :
 * rôles configurables par organisation, dérogations par employé, et
 * surtout révocation à effet immédiat — sans réémission de jeton.
 */
class PermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Route sonde du domaine facturation — le vrai groupe de
        // routes est encore vide.
        Route::middleware(['api', 'auth:sanctum', 'clinique', 'can:facturation'])
            ->get('/api/v1/facturation/sonde', fn () => response()->json(['ok' => true]));
    }

    public function test_l_inscription_d_une_organisation_cree_les_roles_par_defaut(): void
    {
        ['employe' => $employe] = $this->employeAvecJeton();

        $roles = Role::withoutGlobalScopes()
            ->where('organisation_id', $employe->organisation_id)
            ->pluck('domaines', 'nom');

        $this->assertEqualsCanonicalizing(
            [RolesParDefaut::ADMINISTRATEUR, RolesParDefaut::VETERINAIRE, RolesParDefaut::ASV, RolesParDefaut::ASSISTANT],
            $roles->keys()->all(),
        );
        $this->assertNotContains(DomainePermission::Facturation, $roles[RolesParDefaut::ASV]);
        $this->assertNotContains(DomainePermission::Bloc, $roles[RolesParDefaut::ASSISTANT]);
    }

    public function test_un_veterinaire_accede_a_la_facturation_une_asv_non(): void
    {
        ['jeton' => $jetonVeterinaire] = $this->employeAvecJeton();

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jetonVeterinaire))->assertOk();

        ['jeton' => $jetonAsv] = $this->employeAvecJetonAsv();

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jetonAsv))->assertForbidden();
    }

    public function test_modifier_un_role_prend_effet_immediatement_sans_reemettre_le_jeton(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton();

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jeton))->assertOk();

        Role::withoutGlobalScopes()->findOrFail($employe->role_id)->update([
            'domaines' => [DomainePermission::Planning, DomainePermission::Patients],
        ]);

        // Même jeton, requête suivante : refus immédiat.
        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.permissions', ['planning', 'patients']);
    }

    public function test_une_derogation_accorde_un_domaine_au_dela_du_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJetonAsv();

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jeton))->assertForbidden();

        $derogation = DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Facturation,
            'est_accordee' => true,
        ]);

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jeton))->assertOk();

        // Retrait de la dérogation : effet immédiat.
        $derogation->delete();

        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_une_derogation_retire_un_domaine_du_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton();

        $this->getJson('/api/v1/salles', $this->enTetes($jeton))->assertOk();

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Planning,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/salles', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_sans_role_aucune_permission(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton();

        $employe->update(['role_id' => null]);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.permissions', []);
        $this->getJson('/api/v1/salles', $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/facturation/sonde', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_les_policies_gardent_l_ecriture_aussi(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton();

        $employe->update(['role_id' => null]);

        $this->postJson('/api/v1/salles', [
            'nom' => 'Salle interdite',
            'type' => 'consultation',
        ], $this->enTetes($jeton))->assertForbidden();
    }

    /** @return array{user: User, employe: Employe, jeton: string} */
    private function employeAvecJetonAsv(): array
    {
        $resultat = $this->employeAvecJeton(attributs: ['role_libelle' => 'ASV · Accueil']);

        $organisation = $resultat['employe']->organisation;

        $resultat['employe']->update([
            'role_id' => Role::withoutGlobalScopes()
                ->where('organisation_id', $organisation->id)
                ->where('nom', RolesParDefaut::ASV)
                ->firstOrFail()
                ->id,
        ]);

        return $resultat;
    }
}
