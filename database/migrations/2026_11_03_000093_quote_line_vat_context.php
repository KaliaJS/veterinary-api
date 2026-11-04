<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TVA par ligne (03.11.2026) : dans un devis, un médicament « remis pour
 * la maison » suit le taux de vente de l'article ; le reste suit le soin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devis_lignes', function (Blueprint $table): void {
            $table->enum('vat_context', ['care', 'sale'])->nullable()->after('produit_id')
                ->comment('sale : remis pour la maison (taux de vente) ; care ou null : donné pendant le soin');
        });
    }

    public function down(): void
    {
        Schema::table('devis_lignes', function (Blueprint $table): void {
            $table->dropColumn('vat_context');
        });
    }
};
