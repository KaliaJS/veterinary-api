<?php

use App\Enums\CategorieProduit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue de NIVEAU ORGANISATION (partagé entre cliniques) ; les
     * quantités vivent dans `stocks`, par clinique (docs/data-model.md
     * §14). La péremption du produit est indicative — la vérité par lot
     * vit dans `lots`. `promo_pct`, `est_vedette` et `ean` servent la
     * vitrine boutique.
     */
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('nom');
            $table->enum('categorie', array_column(CategorieProduit::cases(), 'value'));
            $table->string('reference', 20)->comment('Référence interne « MED-0114 »');
            $table->decimal('prix', 8, 2)->comment('Prix de vente TTC CHF');
            $table->decimal('cout', 8, 2)->default(0)->comment('Prix d’achat CHF');
            $table->string('unite', 30)->default('pièce');
            $table->unsignedSmallInteger('seuil_alerte')->default(0);
            $table->boolean('est_boutique')->default(false);
            $table->boolean('est_sur_ordonnance')->default(false)
                ->comment('Délivrable uniquement sur prescription active');
            $table->date('peremption_le')->nullable()
                ->comment('Premier jour du mois de péremption — rendu « MM.YYYY »');
            $table->string('fournisseur')->nullable();
            $table->string('ean', 20)->nullable();
            $table->unsignedTinyInteger('promo_pct')->default(0);
            $table->boolean('est_vedette')->default(false);
            $table->timestamps();

            $table->index(['organisation_id', 'nom']);
            $table->index(['organisation_id', 'categorie']);
            $table->unique(['organisation_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
