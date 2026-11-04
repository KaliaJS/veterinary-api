<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pourquoi un article est dans le panier automatique (03.11.2026) : son stock
 * et son seuil au moment où il est passé dessous ; d'où il vient s'il a été
 * indisponible chez un autre fournisseur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commande_fournisseur_lignes', function (Blueprint $table): void {
            $table->integer('reason_stock')->nullable();
            $table->unsignedSmallInteger('reason_threshold')->nullable();
            $table->timestamp('reason_at')->nullable();
            $table->string('moved_from', 120)->nullable()->comment('Fournisseur où il était indisponible');
        });
    }

    public function down(): void
    {
        Schema::table('commande_fournisseur_lignes', function (Blueprint $table): void {
            $table->dropColumn(['reason_stock', 'reason_threshold', 'reason_at', 'moved_from']);
        });
    }
};
