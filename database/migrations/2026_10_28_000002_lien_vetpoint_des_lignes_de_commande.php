<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le produit Vetpoint de chaque ligne, gardé à l'envoi (28.10.2026) : un
 * article délié ou relié ailleurs avant la réception garde son prix réel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commande_fournisseur_lignes', function (Blueprint $table): void {
            $table->string('vetpoint_produit_id', 40)->nullable()->after('cout');
        });
    }

    public function down(): void
    {
        Schema::table('commande_fournisseur_lignes', function (Blueprint $table): void {
            $table->dropColumn('vetpoint_produit_id');
        });
    }
};
