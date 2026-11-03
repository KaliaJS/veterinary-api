<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma dentaire : les états « tumeur » et « extraction » (demande des
 * vétérinaires, 04.11.2026). La colonne est une liste fermée : on la redit
 * en entier, écrite ici (pas lue dans l'enum, qui changera encore).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releves_dentaires', function (Blueprint $table): void {
            $table->enum('etat', ['tartre', 'gingivite', 'parodontite', 'fracture', 'tumeur', 'extraction', 'absente'])->change();
        });
    }

    public function down(): void
    {
        // L'ancienne liste ne les connaît pas : une dent extraite redevient
        // « absente » ; une tumeur n'a pas d'équivalent, sa ligne s'efface
        // (la dent redevient « saine »). Sans cela, MySQL refuse le retour.
        DB::table('releves_dentaires')->where('etat', 'extraction')->update(['etat' => 'absente']);
        DB::table('releves_dentaires')->where('etat', 'tumeur')->delete();

        Schema::table('releves_dentaires', function (Blueprint $table): void {
            $table->enum('etat', ['tartre', 'gingivite', 'parodontite', 'fracture', 'absente'])->change();
        });
    }
};
