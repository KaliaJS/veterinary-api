<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les vidéos de la clinique sur son site web (11.11.2026) : la page
     * « Vidéos » paraît d'elle-même ; l'éditeur peut la masquer.
     */
    public function up(): void
    {
        Schema::table('sites_web', function (Blueprint $table) {
            $table->boolean('afficher_videos')->default(true)->after('afficher_email');
        });
    }

    public function down(): void
    {
        Schema::table('sites_web', function (Blueprint $table) {
            $table->dropColumn('afficher_videos');
        });
    }
};
