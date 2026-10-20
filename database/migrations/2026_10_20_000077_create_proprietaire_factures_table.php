<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offre « Plus » des propriétaires (16.10.2026) : tout l'espace client
     * reste gratuit ; Plus (1 CHF par mois, TTC) ne donne que d'autres planètes
     * au jeu de l'accueil. Abonnement Stripe par compte, mêmes colonnes que
     * l'abonnement des organisations, et ses factures.
     */
    public function up(): void
    {
        Schema::create('proprietaire_factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_facture_id')->nullable();
            $table->string('stripe_paiement_id')->nullable();
            $table->string('numero', 40);
            $table->date('emise_le');
            $table->string('libelle');
            $table->json('lignes')->nullable();
            $table->date('periode_debut')->nullable();
            $table->date('periode_fin')->nullable();
            $table->string('statut', 12)->comment('payee | a_payer | annulee');
            $table->timestamp('paye_le')->nullable();
            $table->string('carte')->nullable()->comment('Carte prélevée, « Visa •••• 4242 »');
            $table->decimal('montant', 10, 2)->comment('TTC CHF');
            $table->decimal('montant_ht', 10, 2)->nullable();
            $table->decimal('tva', 10, 2)->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('paiement_url')->nullable();
            $table->json('metadonnees')->nullable();
            $table->timestamps();

            $table->unique('stripe_facture_id');
            $table->unique('stripe_paiement_id');
            $table->index(['user_id', 'emise_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proprietaire_factures');
    }
};
