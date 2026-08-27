<?php

use App\Enums\TypeLigneFacture;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lignes de facture (docs/data-model.md §6) : libellé, quantité,
     * prix unitaire TTC — signé (remises et acomptes déduits en lignes
     * négatives sur la facture finale d'un devis). Le total est dérivé,
     * la TVA extraite au taux normal 8.1 %.
     */
    public function up(): void
    {
        Schema::create('facture_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_id')->constrained()->cascadeOnDelete();
            $table->string('libelle');
            $table->unsignedSmallInteger('quantite')->default(1);
            $table->decimal('prix_chf', 8, 2)->comment('Prix unitaire TTC, signé');
            $table->enum('type', array_column(TypeLigneFacture::cases(), 'value'));
            $table->foreignId('produit_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facture_lignes');
    }
};
