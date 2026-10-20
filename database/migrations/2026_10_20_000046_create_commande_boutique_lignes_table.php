<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lignes d'une commande boutique — le prix TTC est figé à la
     * commande (prix effectif vitrine, promotion comprise).
     */
    public function up(): void
    {
        Schema::create('commande_boutique_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_boutique_id')->constrained('commandes_boutique')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantite');
            $table->decimal('prix_chf', 8, 2)->comment('Prix unitaire TTC figé à la commande');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_boutique_lignes');
    }
};
