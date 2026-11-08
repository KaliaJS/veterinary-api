<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma neurologique (B, choix de Jim, 08.11.2026) : un examen par geste,
 * gardé au dossier de l'animal. Les signes seulement, sans localisation :
 * les pattes, le dos et les nerfs crâniens en JSON (formes tenues par
 * NeuroExam), l'état de conscience et le score de démarche à part. Le jour
 * local du calendrier décide si l'examen se modifie encore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('neuro_exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->date('day')->comment('Jour local du calendrier de la clinique');
            $table->string('mental_status', 16)->nullable()->comment('alert, obtunded, stuporous, comatose');
            $table->unsignedTinyInteger('gait_score')->nullable()->comment('Score de Frankel modifié, 0 à 5');
            $table->json('limbs')->comment('left_front, right_front, left_hind, right_hind');
            $table->json('spine')->comment('painful_regions, cutaneous_trunci, cutoff_at');
            $table->json('cranial_nerves')->comment('Par test : left, right');
            $table->text('note')->nullable();
            $table->foreignId('by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('by_name')->nullable()->comment('Qui l’a fait, tel qu’au jour du geste');
            $table->foreignId('source_organisation_id')->nullable()->comment('Organisation qui a fait l’acte')->constrained('organisations')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neuro_exams');
    }
};
