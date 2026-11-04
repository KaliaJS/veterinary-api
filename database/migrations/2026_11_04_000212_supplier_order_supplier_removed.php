<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un fournisseur retiré (04.11.2026) : son panier automatique ouvert devient
 * un brouillon ordinaire ; ses articles ne comptent plus comme « en
 * commande » pour la commande automatique, qui les passe à la source suivante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table): void {
            $table->timestamp('supplier_removed_at')->nullable()->comment('Le fournisseur de ce panier automatique a été retiré');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table): void {
            $table->dropColumn('supplier_removed_at');
        });
    }
};
