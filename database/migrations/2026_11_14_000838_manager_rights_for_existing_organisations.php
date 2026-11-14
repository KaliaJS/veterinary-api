<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les droits du manager dans les organisations créées avant eux (Jim,
 * 14.11.2026, audit C04, C11, C29) : chaque rôle qui a l'administration
 * reçoit « gérer les absences » et « voir les chiffres », comme
 * l'Administrateur d'une organisation nouvelle ; chaque organisation
 * reçoit le rôle par défaut Manager, s'il n'existe pas déjà un rôle de ce
 * nom. Les valeurs sont écrites ici (pas lues dans RolesParDefaut, qui
 * changera encore).
 */
return new class extends Migration
{
    private const array DROITS_DU_MANAGER = ['gerer_absences', 'voir_chiffres'];

    private const array MANAGER = ['planning', 'patients', 'facturation', 'stocks', 'equipe', 'gerer_absences', 'voir_chiffres'];

    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'domaines']) as $role) {
            $domaines = json_decode($role->domaines, true) ?? [];
            if (in_array('organisation', $domaines, true) && array_diff(self::DROITS_DU_MANAGER, $domaines) !== []) {
                DB::table('roles')->where('id', $role->id)->update([
                    'domaines' => json_encode(array_values(array_unique([...$domaines, ...self::DROITS_DU_MANAGER]))),
                ]);
            }
        }

        foreach (DB::table('organisations')->pluck('id') as $organisationId) {
            $existe = DB::table('roles')->where('organisation_id', $organisationId)->where('nom', 'Manager')->exists();
            if (! $existe) {
                DB::table('roles')->insert([
                    'organisation_id' => $organisationId,
                    'nom' => 'Manager',
                    'domaines' => json_encode(self::MANAGER),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Le rôle Manager que personne ne porte s'en va ; les droits du manager quittent tous les rôles
        DB::table('roles')
            ->where('nom', 'Manager')
            ->whereNotExists(fn ($employes) => $employes->selectRaw('1')->from('employes')->whereColumn('employes.role_id', 'roles.id'))
            ->delete();

        foreach (DB::table('roles')->get(['id', 'domaines']) as $role) {
            $domaines = json_decode($role->domaines, true) ?? [];
            if (array_intersect(self::DROITS_DU_MANAGER, $domaines) !== []) {
                DB::table('roles')->where('id', $role->id)->update([
                    'domaines' => json_encode(array_values(array_diff($domaines, self::DROITS_DU_MANAGER))),
                ]);
            }
        }
    }
};
