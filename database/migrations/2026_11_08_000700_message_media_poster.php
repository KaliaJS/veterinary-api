<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La première image d'une vidéo ronde (demande de Jim, 08.11.2026 : « la
 * vidéo preview ne s'affiche pas tant qu'on a pas cliqué sur play ») :
 * prise par l'app au début de l'enregistrement, envoyée avec la vidéo,
 * rangée réencodée sans métadonnées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_media', function (Blueprint $table): void {
            $table->string('poster_path')->nullable()->after('path')->comment('Première image d’une vidéo, sur le disque privé');
        });
    }

    public function down(): void
    {
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropColumn('poster_path');
        });
    }
};
