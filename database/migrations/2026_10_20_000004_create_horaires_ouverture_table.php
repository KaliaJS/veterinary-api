<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horaires par clinique (docs/data-model.md, divergence corrigée :
     * jamais des constantes ni un réglage global). La pause déjeuner ne
     * s'applique qu'aux lignes de planning « consultation ».
     */
    public function up(): void
    {
        Schema::create('horaires_ouverture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->time('ouverture');
            $table->time('fermeture');
            $table->time('fermeture_samedi')->nullable();
            $table->boolean('samedi_ferme')->default(false);
            $table->boolean('dimanche_ferme')->default(true);
            $table->boolean('pause_active')->default(true);
            $table->time('pause_debut')->nullable();
            $table->time('pause_fin')->nullable();
            $table->timestamps();

            $table->unique('clinique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horaires_ouverture');
    }
};
