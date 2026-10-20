<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lignes de prescription : médicament (FK produit), posologie
     * libre, durée (« Selon besoin » par défaut) — docs/data-model.md
     * §12.
     */
    public function up(): void
    {
        Schema::create('ordonnance_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordonnance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained()->restrictOnDelete();
            $table->string('posologie');
            $table->string('duree')->default('Selon besoin');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordonnance_lignes');
    }
};
