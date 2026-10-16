<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo de l'animal (16.10.2026) : fichier privé au nom imprévisible,
 * servi par une route publique en cache, comme la photo des employés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('photo_chemin')->nullable()->after('puce')->comment('Chemin sur le disque local (photos-animaux/{organisation}/…)');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn('photo_chemin');
        });
    }
};
