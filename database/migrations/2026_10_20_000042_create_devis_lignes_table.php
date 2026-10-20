<?php

use App\Enums\TypeLigneFacture;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lignes d'un devis — même forme que les lignes de facture
     * (docs/data-model.md §7) ; la facture finale les recopie.
     */
    public function up(): void
    {
        Schema::create('devis_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devis_id')->constrained()->cascadeOnDelete();
            $table->string('libelle');
            $table->unsignedSmallInteger('quantite')->default(1);
            $table->decimal('prix_chf', 8, 2)->comment('Prix unitaire TTC');
            $table->enum('type', array_column(TypeLigneFacture::cases(), 'value'));
            $table->foreignId('produit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devis_lignes');
    }
};
