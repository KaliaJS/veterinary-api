<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quantité par produit × clinique (docs/data-model.md §14). Jamais
     * négative : les sorties sont bornées à zéro, l'écart réel est celui
     * tracé dans `mouvements_stock`.
     */
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantite')->default(0);
            $table->timestamps();

            $table->unique(['produit_id', 'clinique_id']);
            $table->index('clinique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
