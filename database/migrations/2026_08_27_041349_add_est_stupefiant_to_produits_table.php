<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les substances du registre des stupéfiants sont des produits du
     * catalogue (docs/data-model.md §16 : `sub` FK → produit
     * stupéfiant) — le drapeau borne ce que le registre accepte.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->boolean('est_stupefiant')->default(false)->after('est_sur_ordonnance');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('est_stupefiant');
        });
    }
};
