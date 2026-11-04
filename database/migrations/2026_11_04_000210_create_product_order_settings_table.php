<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La commande automatique d'un article se règle par clinique (décision de
 * Jim, 04.11.2026), comme ses sources : l'interrupteur, jusqu'où remonter,
 * et l'écart que la clinique a décidé (article retiré d'un panier
 * automatique, panier supprimé), avec sa raison et qui l'a fait, jusqu'à ce
 * que le stock repasse au-dessus du seuil. La valeur d'avant, réglée pour
 * toute l'organisation, va aux cliniques qui ont des sources pour l'article.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_order_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_auto_order')->default(false)->comment('Sous le seuil, entre seul dans le panier automatique de sa première source dans cette clinique');
            $table->unsignedSmallInteger('reorder_up_to')->nullable()->comment('Quantité visée par le panier ; vide : deux fois le seuil');
            $table->timestamp('excluded_at')->nullable()->comment('Écarté de la commande automatique jusqu’à repasser au-dessus du seuil');
            $table->string('excluded_reason')->nullable();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['produit_id', 'clinique_id']);
        });

        $now = now();
        DB::table('produits')
            ->join('product_sources', 'product_sources.produit_id', '=', 'produits.id')
            ->join('suppliers', 'suppliers.id', '=', 'product_sources.supplier_id')
            ->where('produits.is_auto_order', true)
            ->select('produits.id as produit_id', 'suppliers.clinique_id', 'produits.reorder_up_to')
            ->distinct()
            ->get()
            ->each(fn ($row) => DB::table('product_order_settings')->insertOrIgnore([
                'produit_id' => $row->produit_id,
                'clinique_id' => $row->clinique_id,
                'is_auto_order' => true,
                'reorder_up_to' => $row->reorder_up_to,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('product_order_settings');
    }
};
