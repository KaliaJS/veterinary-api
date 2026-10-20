<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paramètres d'un panel du référentiel : nom, unité et bornes de
     * référence. Les demandes d'analyses en font un instantané
     * (analyse_resultats) — modifier le référentiel ne réécrit jamais
     * une demande passée.
     */
    public function up(): void
    {
        Schema::create('panel_parametres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panel_labo_id')->constrained('panels_labo')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('nom');
            $table->string('unite', 20)->nullable();
            $table->decimal('borne_basse', 8, 2);
            $table->decimal('borne_haute', 8, 2);
            $table->timestamps();

            $table->unique(['panel_labo_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_parametres');
    }
};
