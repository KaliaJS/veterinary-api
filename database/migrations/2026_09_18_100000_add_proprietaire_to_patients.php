<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un animal peut appartenir à un compte client (proprietaire_id) et/ou
 * à une organisation (organisation_id + client_id, la fiche de la
 * clinique). Les deux liens coexistent sur la même fiche ; un animal
 * créé par son propriétaire depuis l'espace n'a pas encore de clinique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('proprietaire_id')->nullable()->after('client_id')
                ->constrained('users')->nullOnDelete()
                ->comment('Compte espace client propriétaire (0..1)');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('organisation_id')->nullable()->change();
            $table->foreignId('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proprietaire_id');
        });
    }
};
