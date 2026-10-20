<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relevés de constantes d'un séjour (docs/data-model.md §8 —
     * `releves_constantes` y est le nom suggéré ; `sejour_constantes`
     * suit la convention parent + enfant des autres tables filles).
     * Valeurs typées : le front affiche « 38,4 °C », la base stocke
     * 38.4 — l'heure de mesure est locale, en minutes du jour.
     */
    public function up(): void
    {
        Schema::create('sejour_constantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sejour_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('mesure_a')->comment('Minutes locales du jour (0–1439)');
            $table->decimal('temperature', 3, 1)->comment('°C');
            $table->unsignedSmallInteger('fc')->comment('Fréquence cardiaque, bpm');
            $table->unsignedSmallInteger('fr')->comment('Fréquence respiratoire, mpm');
            $table->timestamps();

            $table->index(['sejour_id', 'mesure_a']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sejour_constantes');
    }
};
