<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une QR-facture payée depuis la banque du client (01.11.2026) : le moyen
 * « virement ». La colonne est un enum MySQL figé à sa création (tpe,
 * twint, cash) ; sans cette migration, l'écrire est refusé (vérification du
 * 01.11.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table): void {
            $table->enum('moyen_paiement', ['tpe', 'twint', 'cash', 'virement'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table): void {
            $table->enum('moyen_paiement', ['tpe', 'twint', 'cash'])->nullable()->change();
        });
    }
};
