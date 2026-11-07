<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un compte de propriétaire effacé emporte ses fichiers audio et vidéo
 * (décision de Jim, 07.11.2026) : le message, son texte et sa
 * transcription restent au fil de la clinique, sans fichier (`path` vide)
 * et avec la date de l'effacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_media', function (Blueprint $table): void {
            $table->string('path')->nullable()->comment('Sur le disque privé ; vide quand le fichier a été effacé avec le compte')->change();
            $table->timestamp('erased_at')->nullable()->after('path')->comment('Fichier effacé avec le compte de son auteur');
        });
    }

    public function down(): void
    {
        DB::table('message_media')->whereNull('path')->update(['path' => '']);
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropColumn('erased_at');
        });
        Schema::table('message_media', function (Blueprint $table): void {
            $table->string('path')->nullable(false)->comment('Sur le disque privé')->change();
        });
    }
};
