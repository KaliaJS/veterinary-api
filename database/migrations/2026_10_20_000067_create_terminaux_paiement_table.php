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
        Schema::create('terminaux_paiement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_reader_id');
            $table->string('libelle');
            $table->string('modele')->nullable();
            $table->string('numero_serie')->nullable();
            $table->timestamps();

            $table->unique('stripe_reader_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminaux_paiement');
    }
};
