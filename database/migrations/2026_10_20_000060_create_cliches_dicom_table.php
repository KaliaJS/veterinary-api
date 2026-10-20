<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clichés DICOM d'un examen — le flux poussé par la modalité :
     * identifiants DICOM et métadonnées, chemin du fichier stocké
     * (métadonnées seules, comme le dossier documentaire). Idempotent
     * par SOP Instance UID : la modalité ré-émet, l'examen ne double
     * jamais un cliché.
     */
    public function up(): void
    {
        Schema::create('cliches_dicom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_imagerie_id')->constrained('examens_imagerie')->restrictOnDelete();
            $table->string('sop_instance_uid', 64);
            $table->string('study_instance_uid', 64)->nullable();
            $table->string('series_instance_uid', 64)->nullable();
            $table->string('nom_fichier')->nullable();
            $table->string('chemin')->nullable();
            $table->json('metadonnees')->nullable()->comment('Tags DICOM utiles à la visionneuse (kVp, mAs, dimensions…)');
            $table->timestamps();

            $table->unique(['examen_imagerie_id', 'sop_instance_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliches_dicom');
    }
};
