<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le prix et la contenance du produit, figés sur la prise au moment où
 * elle est donnée (deuxième vérification, 03.11.2026) : la facture de
 * sortie les lit là, jamais au jour de la sortie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->decimal('unit_price', 8, 2)->nullable()->comment('Prix de vente d’une unité du produit au moment de la prise');
            $table->decimal('content_per_unit', 10, 3)->nullable()->comment('Contenance d’une unité au moment de la prise');
        });
    }

    public function down(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->dropColumn(['unit_price', 'content_per_unit']);
        });
    }
};
