<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La relecture complète d'une vidéo (31.10.2026, quatrième vérification) :
 * au contrôle de fin d'envoi, quelques morceaux sont relus ; une tâche relit
 * ensuite chaque morceau (fragment MP4, place dans la liste). La mise en
 * vente l'attend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->timestamp('files_checked_at')->nullable()->after('files_verified_at')->comment('Tous les morceaux relus (RecheckVideoFiles)');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('files_checked_at');
        });
    }
};
