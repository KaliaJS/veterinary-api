<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un plafond des ouvertures d'un lien de partage (choix de Jim, 09.11.2026) :
 * ouvrir les pièces une à une n'était pas compté, un script pouvait tout
 * reprendre sans fin. Chaque pièce ou fichier ouvert compte ; au plus 300,
 * davantage pour un gros dossier (8 lectures complètes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_shares', function (Blueprint $table): void {
            $table->unsignedInteger('openings')->default(0)->after('full_downloads');
            $table->unsignedInteger('openings_max')->default(300)->after('openings');
        });
    }

    public function down(): void
    {
        Schema::table('record_shares', function (Blueprint $table): void {
            $table->dropColumn(['openings', 'openings_max']);
        });
    }
};
