<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La quantité à délivrer de chaque médicament (01.11.2026, ordonnance
     * refaite au format A5) : une vraie ordonnance dit combien remettre.
     * C'est aussi ce qui sort du stock à la signature (1 par défaut).
     */
    public function up(): void
    {
        Schema::table('ordonnance_lignes', function (Blueprint $table) {
            $table->unsignedSmallInteger('quantite')->default(1)->after('produit_id');
        });
    }

    public function down(): void
    {
        Schema::table('ordonnance_lignes', function (Blueprint $table) {
            $table->dropColumn('quantite');
        });
    }
};
