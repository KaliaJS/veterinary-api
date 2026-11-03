<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier patient, proposition B (03.11.2026) : le poids de forme de
 * l'animal, saisi par le vétérinaire sur la fiche (bas et haut de la
 * bande). Aucune norme par race n'est inventée : sans saisie, pas de bande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->decimal('healthy_weight_min_kg', 5, 2)->nullable()->comment('Poids de forme, bas de la bande, saisi par le vétérinaire');
            $table->decimal('healthy_weight_max_kg', 5, 2)->nullable()->comment('Poids de forme, haut de la bande, saisi par le vétérinaire');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn(['healthy_weight_min_kg', 'healthy_weight_max_kg']);
        });
    }
};
