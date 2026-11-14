<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les droits du manager (Jim, 14.11.2026, audit C04, C11, C29) : « gérer
 * les absences » et « voir les chiffres » se donnent aussi à titre
 * personnel. La colonne est une liste fermée : on la redit en entier,
 * écrite ici (pas lue dans l'enum, qui changera encore).
 */
return new class extends Migration
{
    private const array AVANT = [
        'planning', 'patients', 'facturation', 'stocks', 'bloc', 'equipe', 'organisation',
        'rh', 'prescrire', 'valider_analyses', 'signer_comptes_rendus', 'stupefiants', 'cloturer_caisse', 'rembourser',
    ];

    public function up(): void
    {
        Schema::table('derogations_permission', function (Blueprint $table): void {
            $table->enum('domaine', [...self::AVANT, 'gerer_absences', 'voir_chiffres'])->change();
        });
    }

    public function down(): void
    {
        // L'ancienne liste ne les connaît pas : ces exceptions s'effacent
        DB::table('derogations_permission')->whereIn('domaine', ['gerer_absences', 'voir_chiffres'])->delete();

        Schema::table('derogations_permission', function (Blueprint $table): void {
            $table->enum('domaine', self::AVANT)->change();
        });
    }
};
