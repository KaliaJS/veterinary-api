<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La mise en vente demandée pendant la relecture complète (31.10.2026,
 * cinquième vérification) : la vidéo se met en vente d'elle-même dès que
 * chaque morceau a été relu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->timestamp('publish_requested_at')->nullable()->after('files_checked_at')->comment('Mise en vente demandée, faite dès la relecture complète');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('publish_requested_at');
        });
    }
};
