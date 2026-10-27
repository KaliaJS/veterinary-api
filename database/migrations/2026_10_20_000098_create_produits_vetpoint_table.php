<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vetpoint (27.10.2026) : le produit Vetpoint relié à un produit du
     * catalogue de l'organisation (un au plus). L'identifiant est le même
     * pour toutes les cliniques ; chacune voit ses prix avec sa clé.
     */
    public function up(): void
    {
        Schema::create('produits_vetpoint', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vetpoint_id', 40)->index();
            $table->string('nom', 190);
            $table->string('swissmedic', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits_vetpoint');
    }
};
