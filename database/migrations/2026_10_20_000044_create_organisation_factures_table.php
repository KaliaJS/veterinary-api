<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Factures d'abonnement et de packs de quota (docs/data-model.md
     * §2) — séquence « F- » propre, distincte des factures clientes
     * « FC- ». Émises par l'éditeur : jamais modifiées ni supprimées.
     */
    public function up(): void
    {
        Schema::create('organisation_factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('stripe_facture_id')->nullable();
            $table->string('stripe_paiement_id')->nullable();
            $table->string('numero', 40);
            $table->date('emise_le');
            $table->string('libelle');
            $table->json('lignes')->nullable();
            $table->date('periode_debut')->nullable();
            $table->date('periode_fin')->nullable();
            $table->string('statut', 12)->default('payee')->comment('payee | a_payer | annulee (miroir du statut Stripe)');
            $table->timestamp('paye_le')->nullable();
            $table->string('carte')->nullable()->comment('Carte prélevée, « Visa •••• 4242 »');
            $table->decimal('montant', 8, 2)->comment('TTC CHF — TVA 8.1 % incluse');
            $table->decimal('montant_ht', 10, 2)->nullable();
            $table->decimal('tva', 10, 2)->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('paiement_url')->nullable()->comment('Page Stripe pour régler une facture à payer');
            $table->json('metadonnees')->nullable();
            $table->boolean('quota_applique')->default(false)->comment('Pack de quota : quantité ajoutée une seule fois, au paiement');
            $table->timestamps();

            $table->unique('numero');
            $table->index(['organisation_id', 'emise_le']);
            $table->unique('stripe_facture_id');
            $table->unique('stripe_paiement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_factures');
    }
};
