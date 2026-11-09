<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IA pour le rapport de bloc (09.11.2026) : un enregistrement peut aussi
 * être la dictée d'un chirurgien, adossée à son intervention plutôt qu'à
 * un rendez-vous. L'un ou l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enregistrements_consultation', function (Blueprint $table): void {
            $table->foreignId('rendez_vous_id')->nullable()->change();
            $table->foreignId('intervention_id')->nullable()->after('rendez_vous_id')->comment('Dictée du rapport de bloc')->constrained('interventions')->cascadeOnDelete();
            $table->index(['intervention_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::table('enregistrements_consultation', function (Blueprint $table): void {
            $table->dropIndex(['intervention_id', 'statut']);
            $table->dropConstrainedForeignId('intervention_id');
        });
    }
};
