<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapport de bloc, 3e vérification (09.11.2026) : l'heure de la dernière
 * correction de chaque rubrique ; la fenêtre ne propose un nouveau texte
 * sous une rubrique corrigée que si le brouillon est plus récent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_reports', function (Blueprint $table): void {
            $table->json('edited_at')->nullable()->after('edited_sections')->comment('Rubrique → heure de sa dernière correction');
        });
    }

    public function down(): void
    {
        Schema::table('surgery_reports', function (Blueprint $table): void {
            $table->dropColumn('edited_at');
        });
    }
};
