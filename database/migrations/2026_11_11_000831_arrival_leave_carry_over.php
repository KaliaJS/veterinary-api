<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le report à l'arrivée (Jim, 11.11.2026, É22) : les jours de congé
     * d'avant Veterinary, posés une fois, ajoutés à la première année
     * comptée. Le nombre de jours pris saisi à la main (`conges_pris`,
     * retiré ensuite) y passe en négatif : ce qu'il retirait du solde
     * reste retiré (les soldes se comptent désormais en jours ouvrés,
     * d'après les absences validées).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('employes', 'conges_report_arrivee')) {
            Schema::table('employes', function (Blueprint $table) {
                $table->decimal('conges_report_arrivee', 4, 1)->default(0);
            });
        }
        if (Schema::hasColumn('employes', 'conges_pris')) {
            DB::table('employes')->where('conges_pris', '>', 0)->update(['conges_report_arrivee' => DB::raw('-conges_pris')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employes', 'conges_report_arrivee')) {
            Schema::table('employes', function (Blueprint $table) {
                $table->dropColumn('conges_report_arrivee');
            });
        }
    }
};
