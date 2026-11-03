<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fenêtre propriétaire (03.11.2026) : un numéro de version, augmenté à
 * chaque enregistrement de la fiche. Le web écarte une lecture partie avant
 * un enregistrement et arrivée après lui (la date, à la seconde, ne
 * distingue pas deux écritures rapprochées).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0)->comment('Augmentée à chaque enregistrement de la fiche');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
