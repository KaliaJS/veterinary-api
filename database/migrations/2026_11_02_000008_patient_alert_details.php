<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les précisions des alertes d'un animal (demande de Jim, 02.11.2026) :
 * agressif dans quels cas et muselière ; allergique à quoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->json('aggression_triggers')->nullable()->after('est_agressif')->comment('Agressif dans quels cas (AggressionTrigger)');
            $table->boolean('needs_muzzle')->default(false)->after('aggression_triggers');
            $table->json('allergens')->nullable()->after('a_allergies')->comment('Allergique à quoi (Allergen)');
            $table->string('allergy_note')->nullable()->after('allergens')->comment('Allergies en texte libre (molécule, aliment)');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn(['aggression_triggers', 'needs_muzzle', 'allergens', 'allergy_note']);
        });
    }
};
