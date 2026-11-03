<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal se cherche par article, clinique et flacon (pourquoi un flacon
 * n'est plus là, dixième vérification, 03.11.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table): void {
            $table->index(['produit_id', 'clinique_id', 'opening_number'], 'mouvements_stock_produit_clinique_flacon_index');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table): void {
            $table->dropIndex('mouvements_stock_produit_clinique_flacon_index');
        });
    }
};
