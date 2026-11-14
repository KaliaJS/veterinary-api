<?php

namespace Tests\Feature\Auth;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Models\Absence;
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
            [RolesParDefaut::ADMINISTRATEUR, RolesParDefaut::MANAGER, RolesParDefaut::VETERINAIRE, RolesParDefaut::ASV, RolesParDefaut::ASSISTANT],
            $roles->keys()->all(),
        );
        // Défauts du 23.10.2026 : l'ASV encaisse, le vétérinaire signe,
        // l'administrateur seul gère l'équipe, clôture et rembourse
        $this->assertContains(DomainePermission::Facturation, $roles[RolesParDefaut::ASV]);
        $this->assertNotContains(DomainePermission::Prescrire, $roles[RolesParDefaut::ASV]);
        $this->assertNotContains(DomainePermission::Rh, $roles[RolesParDefaut::ASV]);
        $this->assertNotContains(DomainePermission::Facturation, $roles[RolesParDefaut::ASSISTANT]);
        $this->assertNotContains(DomainePermission::Bloc, $roles[RolesParDefaut::ASSISTANT]);
        foreach ([DomainePermission::Prescrire, DomainePermission::ValiderAnalyses, DomainePermission::SignerComptesRendus, DomainePermission::Stupefiants] as $acte) {
            $this->assertContains($acte, $roles[RolesParDefaut::VETERINAIRE]);
        }
        foreach ([DomainePermission::Rh, DomainePermission::Organisation, DomainePermission::CloturerCaisse, DomainePermission::Rembourser] as $geste) {
            $this->assertNotContains($geste, $roles[RolesParDefaut::VETERINAIRE]);
            $this->assertContains($geste, $roles[RolesParDefaut::ADMINISTRATEUR]);
        }
    }

    /**
     * Les droits du manager (Jim, 14.11.2026, audit C04, C11, C29) : gérer
     * les absences et voir les chiffres, à l'administrateur et au rôle
     * Manager seulement ; « Voir l'équipe » reste à tous.
     */
    public function test_seuls_l_administrateur_et_le_manager_gerent_les_absences_et_voient_les_chiffres(): void
    {
        ['employe' => $employe] = $this->employeAvecJeton();

        $roles = Role::withoutGlobalScopes()
            ->where('organisation_id', $employe->organisation_id)
            ->pluck('domaines', 'nom');

        foreach ([DomainePermission::GererAbsences, DomainePermission::VoirChiffres] as $droit) {
            $this->assertContains($droit, $roles[RolesParDefaut::ADMINISTRATEUR]);
            $this->assertContains($droit, $roles[RolesParDefaut::MANAGER]);
            foreach ([RolesParDefaut::VETERINAIRE, RolesParDefaut::ASV, RolesParDefaut::ASSISTANT] as $autre) {
                $this->assertNotContains($droit, $roles[$autre], $autre);
            }
        }
        foreach ($roles as $nom => $domaines) {
            $this->assertContains(DomainePermission::Equipe, $domaines, $nom);
        }
        // Le manager organise, il ne gère ni l'organisation ni les contrats, et ne signe aucun acte
        foreach ([DomainePermission::Organisation, DomainePermission::Rh, DomainePermission::Prescrire, DomainePermission::Stupefiants,
            DomainePermission::ValiderAnalyses, DomainePermission::SignerComptesRendus, DomainePermission::Bloc,
            DomainePermission::CloturerCaisse, DomainePermission::Rembourser] as $exclu) {
            $this->assertNotContains($exclu, $roles[RolesParDefaut::MANAGER]);
        }
    }

    /**
     * L'administration emporte les droits du manager (vérification du
     * 14.11.2026) : un administrateur par exception (rôle Vétérinaire et
     * « Administration » accordée à titre personnel) gère les absences et
     * voit les chiffres, comme le rôle Administrateur.
     */
    public function test_l_administration_emporte_les_droits_du_manager_meme_par_exception(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton();
        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Organisation, 'est_accordee' => true]);
        $collegue = Employe::factory()->create(['organisation_id' => $employe->organisation_id]);
        $demande = Absence::factory()->enAttente()->create([
            'employe_id' => $collegue->id,
            'debut_le' => aujourdhui()->addDays(10),
            'fin_le' => aujourdhui()->addDays(11),
        ]);

        $permissions = $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk()->json('data.permissions');
        $this->assertContains('gerer_absences', $permissions);
        $this->assertContains('voir_chiffres', $permissions);
        $this->postJson("/api/v1/absences/{$demande->id}/valider", [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'ok');
    }

    public function test_les_droits_du_manager_se_donnent_a_titre_personnel(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton();

        foreach ([DomainePermission::GererAbsences, DomainePermission::VoirChiffres] as $droit) {
            DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => $droit, 'est_accordee' => true]);
        }

        $permissions = $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk()->json('data.permissions');
        $this->assertContains('gerer_absences', $permissions);
        $this->assertContains('voir_chiffres', $permissions);
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
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJetonAssistant();

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
    private function employeAvecJetonAssistant(): array
    {
        $resultat = $this->employeAvecJeton(attributs: ['role_libelle' => 'Assistant']);

        $organisation = $resultat['employe']->organisation;

        $resultat['employe']->update([
            'role_id' => Role::withoutGlobalScopes()
                ->where('organisation_id', $organisation->id)
                ->where('nom', RolesParDefaut::ASSISTANT)
                ->firstOrFail()
                ->id,
        ]);

        return $resultat;
    }
}
