<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TVA par ligne, suite de la vérification (03.11.2026) : deux contextes
 * de plus. Une remise (ligne négative sans article) se répartit au
 * prorata des taux de la facture ; la déduction d'un acompte reprend le
 * taux de la facture d'acompte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facture_lignes', function (Blueprint $table): void {
            $table->enum('vat_context', ['care', 'sale', 'discount', 'deposit'])->nullable()->change()
                ->comment('care : soin, ou article donné pendant le soin ; sale : article remis ou vendu ; discount : remise répartie ; deposit : déduction d’un acompte');
        });
    }

    public function down(): void
    {
        Schema::table('facture_lignes', function (Blueprint $table): void {
            $table->enum('vat_context', ['care', 'sale'])->nullable()->change()
                ->comment('care : soin, ou article donné pendant le soin ; sale : article remis au propriétaire ou vendu');
        });
    }
};
