<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les séries de vidéos (31.10.2026, demande de Jim) : une série est une
 * ligne de `videos` sans fichiers, qui se vend entière ; ses épisodes sont
 * des vidéos rattachées, dans l'ordre de leur numéro. Une série achetée
 * donne tous ses épisodes, y compris ceux ajoutés ensuite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('kind', 10)->default('video')->index()->after('uuid')->comment('VideoKind : vidéo seule, série ou épisode');
            $table->foreignId('series_id')->nullable()->after('kind')->comment('La série d’un épisode')->constrained('videos')->restrictOnDelete();
            $table->unsignedSmallInteger('episode_number')->nullable()->after('series_id');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn(['kind', 'episode_number']);
        });
    }
};
