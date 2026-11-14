<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
use App\Models\Employe;
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
        // Une organisation d'avant le lot : un administrateur sans les droits du manager, pas de rôle Manager
        $organisation = $this->organisationAvecLesRoles(['Direction' => self::AVANT, 'Soins' => ['planning', 'patients']]);

        $this->migration('2026_11_14_000838_manager_rights_for_existing_organisations.php')->up();

        $this->assertEqualsCanonicalizing([...self::AVANT, 'gerer_absences', 'voir_chiffres'], $this->droitsDu($organisation, 'Direction'));
        $this->assertEqualsCanonicalizing(['planning', 'patients', 'facturation', 'stocks', 'equipe', 'gerer_absences', 'voir_chiffres'], $this->droitsDu($organisation, 'Manager'));
        // Un rôle sans l'administration ne bouge pas
        $this->assertSame(['planning', 'patients'], $this->droitsDu($organisation, 'Soins'));
    }

    public function test_un_role_deja_nomme_manager_n_est_pas_remplace(): void
    {
        $organisation = $this->organisationAvecLesRoles(['Manager' => ['planning']]);

        $this->migration('2026_11_14_000838_manager_rights_for_existing_organisations.php')->up();

        $this->assertSame(['planning'], $this->droitsDu($organisation, 'Manager'));
    }

    public function test_le_retour_en_arriere_retire_les_droits_sans_effacer_aucun_role(): void
    {
        $organisation = $this->organisationAvecLesRoles([
            'Direction' => [...self::AVANT, 'gerer_absences', 'voir_chiffres'],
            'Manager' => ['planning'],
            'Chef de clinique' => ['planning', 'gerer_absences'],
        ]);

        $this->migration('2026_11_14_000838_manager_rights_for_existing_organisations.php')->down();

        $this->assertSame(3, DB::table('roles')->where('organisation_id', $organisation)->count());
        // Seulement ces deux-là : la direction garde tout le reste
        $this->assertEqualsCanonicalizing(self::AVANT, $this->droitsDu($organisation, 'Direction'));
        $this->assertSame(['planning'], $this->droitsDu($organisation, 'Manager'));
        $this->assertSame(['planning'], $this->droitsDu($organisation, 'Chef de clinique'));
    }

    /**
     * Une organisation et ses rôles écrits tels quels, sans ceux que crée
     * l'application aujourd'hui : la migration se teste sur une base d'avant.
     *
     * @param  array<string, list<string>>  $roles
     */
    private function organisationAvecLesRoles(array $roles): int
    {
        $organisation = Clinique::factory()->create()->societe->organisation_id;
        DB::table('roles')->where('organisation_id', $organisation)->delete();
        foreach ($roles as $nom => $domaines) {
            DB::table('roles')->insert(['organisation_id' => $organisation, 'nom' => $nom, 'domaines' => json_encode($domaines), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $organisation;
    }

    /** @return list<string> */
    private function droitsDu(int $organisation, string $nom): array
    {
        return json_decode(DB::table('roles')->where('organisation_id', $organisation)->where('nom', $nom)->value('domaines'), true);
    }

    private function migration(string $fichier): object
    {
        return require database_path('migrations/'.$fichier);
    }
}
