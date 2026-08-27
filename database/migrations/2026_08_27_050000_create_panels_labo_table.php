<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel des panels d'analyses (LAB_PANELS du front, docs/
     * data-model.md §9) — PAR ORGANISATION, créé à sa naissance comme
     * le catalogue de prestations : un point de départ modifiable
     * (bornes de référence ajustables par organisation). Le tube de
     * prélèvement est porté par le panel ; les tubes affichés d'une
     * demande en dérivent.
     */
    public function up(): void
    {
        Schema::create('panels_labo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('nom');
            $table->string('tube', 20)->comment('EDTA, SEC, HÉPARINE, URINE, CITRATE…');
            $table->timestamps();

            $table->unique(['organisation_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panels_labo');
    }
};
