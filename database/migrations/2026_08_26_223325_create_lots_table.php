<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lots de péremption par clinique de détention (docs/data-model.md
     * §14, FEFO). Écarter un lot est l'une des suppressions tolérées :
     * le stock sort avec un mouvement motivé « Péremption » puis le lot
     * disparaît. La réception d'une commande en crée un.
     */
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->string('numero_lot', 30);
            $table->date('peremption_le')->comment('Premier jour du mois de péremption — rendu « MM.YYYY »');
            $table->unsignedInteger('quantite');
            $table->timestamps();

            $table->index(['clinique_id', 'peremption_le']);
            $table->index('produit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};
