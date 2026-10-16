<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Droits sur l'animal (16.10.2026) : quand le propriétaire gère la fiche
 * depuis son compte (proprietaire_id posé), il choisit ce que la clinique
 * peut faire de l'identité de l'animal : la modifier (défaut, comme
 * avant) ou seulement la consulter. Sans propriétaire, la clinique
 * garde la main.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('acces_clinique', 12)->default('modification')->after('proprietaire_id')
                ->comment('modification | lecture : droit de la clinique sur l identité, décidé par le propriétaire');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn('acces_clinique');
        });
    }
};
