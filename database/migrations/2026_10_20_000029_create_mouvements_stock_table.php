<?php

use App\Enums\MotifAjustementStock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal immuable des entrées/sorties (docs/data-model.md §14 :
     * jamais de décrément silencieux). `quantite` est l'écart réellement
     * appliqué (signé, borné par le zéro du stock) ; `source` référence
     * l'acte à l'origine du geste (facture, ordonnance, commande, lot) ;
     * `motif` n'est renseigné que pour les ajustements manuels.
     */
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantite')->comment('Écart réel appliqué, signé');
            $table->string('libelle');
            $table->enum('motif', array_column(MotifAjustementStock::cases(), 'value'))->nullable()->comment('Ajustements manuels uniquement — toujours motivés');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('created_at');

            $table->index(['clinique_id', 'created_at']);
            $table->index(['produit_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};
