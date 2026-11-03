<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitalisation, la ronde (03.11.2026) : la douleur notée sur l'échelle
 * de l'espèce (Glasgow abrégée CMPS-SF sur 24, seuil 6, pour le chien ;
 * Feline Grimace Scale sur 10, seuil 4, pour le chat ; Colorado sur 4,
 * seuil 2, pour les autres). L'échelle, son maximum et son seuil sont
 * gardés avec le score, l'heure locale et l'auteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_pain_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sejour_id')->constrained('sejours')->cascadeOnDelete();
            $table->date('day')->comment('Jour local du calendrier');
            $table->unsignedSmallInteger('minute')->comment('Minutes locales du jour (0 à 1439)');
            $table->string('scale', 16)->comment('cmps_sf, fgs, colorado');
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('max');
            $table->unsignedTinyInteger('threshold')->comment('Seuil de traitement de l’échelle');
            $table->string('note')->nullable();
            $table->foreignId('by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('by_name')->nullable()->comment('Qui l’a évaluée, tel qu’au jour du geste');
            $table->timestamps();

            $table->index(['sejour_id', 'day', 'minute']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_pain_scores');
    }
};
