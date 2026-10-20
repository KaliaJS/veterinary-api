<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paiements en caisse par Stripe (docs/data-model.md §6) :
     * - la clinique porte sa « location » Stripe Terminal (créée au premier
     *   lecteur appairé) ;
     * - les terminaux de paiement appairés à la clinique ;
     * - les paiements (une intention Stripe par tentative : TPE ou TWINT) ;
     * - les événements webhook déjà traités (déduplication par id).
     */
    public function up(): void
    {
        Schema::create('evenements_stripe', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('traite_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evenements_stripe');
    }
};
