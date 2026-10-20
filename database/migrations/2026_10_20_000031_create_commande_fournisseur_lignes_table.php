<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lignes d'un bon de commande — `cout` fige le prix d'achat au
     * moment de la commande (docs/data-model.md §14).
     */
    public function up(): void
    {
        Schema::create('commande_fournisseur_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_fournisseur_id')->constrained('commandes_fournisseur')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantite');
            $table->decimal('cout', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_fournisseur_lignes');
    }
};
