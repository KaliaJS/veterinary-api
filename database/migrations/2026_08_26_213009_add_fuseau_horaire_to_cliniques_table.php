<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les horaires d'ouverture sont des heures LOCALES (colonnes time),
     * pas des instants : les stocker en UTC les décalerait au
     * changement d'heure. Le fuseau vit sur la clinique ; les
     * rendez-vous, eux, restent des datetimes UTC.
     */
    public function up(): void
    {
        Schema::table('cliniques', function (Blueprint $table) {
            $table->string('fuseau_horaire')->default('Europe/Zurich')->after('tel');
        });
    }

    public function down(): void
    {
        Schema::table('cliniques', function (Blueprint $table) {
            $table->dropColumn('fuseau_horaire');
        });
    }
};
