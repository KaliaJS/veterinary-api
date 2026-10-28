<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vente comptoir payée au terminal ou par TWINT (28.10.2026) : la facture
 * de la boutique est marquée ; non payée, elle s'annule et reste au journal
 * (statut « annulée »), jamais effacée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table): void {
            $table->boolean('vente_comptoir')->default(false)->after('statut');
            $table->dateTime('annulee_le')->nullable()->after('payee_le');
            $table->enum('statut', ['due', 'payee', 'annulee'])->default('due')->change();
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table): void {
            $table->dropColumn(['vente_comptoir', 'annulee_le']);
            $table->enum('statut', ['due', 'payee'])->default('due')->change();
        });
    }
};
