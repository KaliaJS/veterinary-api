<?php

namespace Tests\Feature\Auth;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les droits du manager sur une base qui existait avant eux (audit C04,
 * C11, C29 ; vérification du 14.11.2026) : la liste fermée des exceptions
 * les accepte, l'administrateur de chaque organisation les reçoit, et
 * chaque organisation a son rôle Manager.
 */
class DroitsDuManagerMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const array AVANT = [
        'planning', 'patients', 'facturation', 'stocks', 'bloc', 'equipe', 'organisation',
        'rh', 'prescrire', 'valider_analyses', 'signer_comptes_rendus', 'stupefiants', 'cloturer_caisse', 'rembourser',
    ];

    public function test_la_liste_des_exceptions_accepte_les_droits_du_manager_apres_la_migration(): void
    {
        $employe = Employe::factory()->dans(Clinique::factory()->create())->create();
        $liste = $this->migration('2026_11_14_000837_rights_to_manage_absences_and_see_figures.php');

        $liste->down();
        try {
            DB::table('derogations_permission')->insert(['employe_id' => $employe->id, 'domaine' => 'gerer_absences', 'est_accordee' => true]);
            $this->fail('L’ancienne liste fermée accepte un droit qu’elle ne connaît pas.');
        } catch (QueryException) {
            // L'ancienne liste refuse le nouveau droit
        }

        $liste->up();
        DB::table('derogations_permission')->insert(['employe_id' => $employe->id, 'domaine' => 'gerer_absences', 'est_accordee' => true]);
        $this->assertDatabaseHas('derogations_permission', ['employe_id' => $employe->id, 'domaine' => 'gerer_absences']);
    }

    public function test_l_administrateur_d_une_organisation_existante_recoit_les_droits_et_le_role_manager_est_cree(): void
    {
        $clinique = Clinique::factory()->create();
        $organisation = $clinique->societe->organisation_id;
        Employe::factory()->dans($clinique)->administrateur()->create();
        // Une organisation d'avant le lot : l'administrateur sans les droits du manager, pas de rôle Manager
        DB::table('roles')->where('organisation_id', $organisation)->where('nom', RolesParDefaut::ADMINISTRATEUR)->update(['domaines' => json_encode(self::AVANT)]);
        DB::table('roles')->where('organisation_id', $organisation)->where('nom', RolesParDefaut::MANAGER)->delete();
        $veterinaire = DB::table('roles')->where('organisation_id', $organisation)->where('nom', RolesParDefaut::VETERINAIRE)->value('domaines');

        $this->migration('2026_11_14_000838_manager_rights_for_existing_organisations.php')->up();

        $roles = Role::withoutGlobalScopes()->where('organisation_id', $organisation)->get()->keyBy('nom');
        $valeurs = fn (string $nom): array => $roles[$nom]->domaines->map->value->all();
        $this->assertContains('gerer_absences', $valeurs(RolesParDefaut::ADMINISTRATEUR));
        $this->assertContains('voir_chiffres', $valeurs(RolesParDefaut::ADMINISTRATEUR));
        $this->assertEqualsCanonicalizing(
            collect(RolesParDefaut::droits()[RolesParDefaut::MANAGER])->map->value->all(),
            $valeurs(RolesParDefaut::MANAGER),
        );
        // Les autres rôles ne bougent pas
        $this->assertSame($veterinaire, DB::table('roles')->where('organisation_id', $organisation)->where('nom', RolesParDefaut::VETERINAIRE)->value('domaines'));
    }

    public function test_un_role_deja_nomme_manager_n_est_pas_remplace(): void
    {
        $clinique = Clinique::factory()->create();
        $organisation = $clinique->societe->organisation_id;
        Employe::factory()->dans($clinique)->create();
        DB::table('roles')->where('organisation_id', $organisation)->where('nom', RolesParDefaut::MANAGER)->update(['domaines' => json_encode(['planning'])]);

        $this->migration('2026_11_14_000838_manager_rights_for_existing_organisations.php')->up();

        $this->assertSame(['planning'], json_decode(DB::table('roles')->where('organisation_id', $organisation)->where('nom', RolesParDefaut::MANAGER)->value('domaines'), true));
    }

    private function migration(string $fichier): object
    {
        return require database_path('migrations/'.$fichier);
    }
}
