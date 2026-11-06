<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statistiques de la Caisse (demande de Jim, 06.11.2026) : le choix entre
 * les barres et la courbe, gardé avec les autres préférences de la personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_preferences', function (Blueprint $table): void {
            $table->string('stats_chart_style', 8)->nullable()->comment('Graphique des statistiques de la Caisse : bars (barres) ou line (courbe)');
        });
    }

    public function down(): void
    {
        Schema::table('staff_preferences', function (Blueprint $table): void {
            $table->dropColumn('stats_chart_style');
        });
    }
};
