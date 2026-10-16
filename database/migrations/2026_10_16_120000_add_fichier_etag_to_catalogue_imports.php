<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ETag annoncé par le serveur pour le fichier importé : renvoyé au
 * téléchargement suivant, Swissmedic répond « inchangé » sans rien envoyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_imports', function (Blueprint $table): void {
            $table->string('fichier_etag', 128)->nullable()->after('fichier_hash')->comment('ETag du serveur (If-None-Match au passage suivant)');
        });
    }

    public function down(): void
    {
        Schema::table('catalogue_imports', function (Blueprint $table): void {
            $table->dropColumn('fichier_etag');
        });
    }
};
