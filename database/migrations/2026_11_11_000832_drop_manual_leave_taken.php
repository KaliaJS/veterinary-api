<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les jours de congé pris ne se saisissent plus à la main (Jim,
     * 11.11.2026, É21) : ils se comptent en jours ouvrés d'après les
     * absences validées (SoldesConges) ; l'ancien nombre est passé dans le
     * report à l'arrivée (migration précédente).
     */
    public function up(): void
    {
        if (Schema::hasColumn('employes', 'conges_pris')) {
            Schema::table('employes', function (Blueprint $table) {
                $table->dropColumn('conges_pris');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employes', 'conges_pris')) {
            Schema::table('employes', function (Blueprint $table) {
                $table->unsignedTinyInteger('conges_pris')->default(0);
            });
            DB::table('employes')->where('conges_report_arrivee', '<', 0)->update(['conges_pris' => DB::raw('-conges_report_arrivee')]);
        }
    }
};
