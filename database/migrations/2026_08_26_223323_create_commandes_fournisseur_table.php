<?php

use App\Enums\StatutCommandeFournisseur;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bons de commande fournisseurs, rattachés à la clinique de
     * livraison (`dest` du front — docs/data-model.md §14). La réception
     * incrémente le stock de cette clinique, trace les mouvements et
     * crée un lot.
     */
    public function up(): void
    {
        Schema::create('commandes_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete()
                ->comment('Clinique de livraison');
            $table->string('numero', 20)->unique();
            $table->string('fournisseur');
            $table->enum('statut', array_column(StatutCommandeFournisseur::cases(), 'value'))
                ->default(StatutCommandeFournisseur::Brouillon->value);
            $table->dateTime('envoyee_le')->nullable();
            $table->dateTime('receptionnee_le')->nullable();
            $table->timestamps();

            $table->index(['clinique_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes_fournisseur');
    }
};
