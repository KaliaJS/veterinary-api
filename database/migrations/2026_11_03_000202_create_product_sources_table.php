<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les sources d'un article, rangées par préférence (03.11.2026) : chez quel
 * fournisseur de la clinique on le commande, sous quelle référence, à quel
 * prix d'achat. Indisponible chez la première, l'article passe à la suivante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank')->comment('Préférence, 1 d’abord');
            $table->string('reference', 60)->nullable()->comment('Référence chez ce fournisseur');
            $table->decimal('cost', 10, 2)->nullable()->comment('Prix d’achat chez ce fournisseur, CHF');
            $table->timestamps();

            $table->unique(['produit_id', 'supplier_id']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sources');
    }
};
