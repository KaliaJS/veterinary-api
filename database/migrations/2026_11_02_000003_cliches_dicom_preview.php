<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'aperçu d'une radio (choix de Jim, 02.11.2026) : une image légère
     * (JPEG, environ 800 px) faite par l'app à partir du DICOM d'origine, qui
     * reste intact. L'examen l'affiche en grand sans relire le fichier entier.
     */
    public function up(): void
    {
        Schema::table('cliches_dicom', function (Blueprint $table) {
            $table->string('preview_path')->nullable()->after('chemin');
            $table->unsignedInteger('preview_bytes')->nullable()->after('preview_path');
        });
    }

    public function down(): void
    {
        Schema::table('cliches_dicom', function (Blueprint $table) {
            $table->dropColumn(['preview_path', 'preview_bytes']);
        });
    }
};
