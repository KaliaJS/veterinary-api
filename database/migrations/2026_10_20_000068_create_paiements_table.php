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
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->string('moyen');
            $table->decimal('montant', 10, 2);
            $table->string('stripe_payment_intent_id');
            $table->string('stripe_compte_id')->nullable()->comment('Compte Stripe de la société au moment du paiement (suivi, remboursement), 23.10.2026');
            $table->foreignId('terminal_paiement_id')->nullable()->constrained('terminaux_paiement')->nullOnDelete();
            $table->string('statut')->default('en_attente');
            $table->string('erreur')->nullable();
            $table->text('url_twint')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reussi_le')->nullable();
            $table->timestamps();

            $table->index(['facture_id', 'statut']);
            $table->unique('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
