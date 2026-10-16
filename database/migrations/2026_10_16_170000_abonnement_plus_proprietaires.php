<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offre « Plus » des propriétaires (16.10.2026) : tout l'espace client
 * reste gratuit ; Plus (1 CHF par mois, TTC) ne donne que d'autres planètes
 * au jeu de l'accueil. Abonnement Stripe par compte, mêmes colonnes que
 * l'abonnement des organisations, et ses factures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('formule', 12)->default('gratuit')->comment('gratuit | plus');
            $table->string('stripe_client_id')->nullable()->unique();
            $table->string('stripe_abonnement_id')->nullable()->unique();
            $table->string('stripe_moyen_paiement_id')->nullable();
            $table->string('moyen_paiement')->nullable()->comment('Libellé masqué « Visa •••• 4242 · exp. 08/28 »');
            $table->string('statut_abonnement', 12)->nullable()->comment('actif | impaye | incomplet | resilie ; null : jamais abonné');
            $table->date('periode_fin')->nullable();
            $table->date('resilie_le')->nullable()->comment('Retour à Gratuit à cette date (fin de la période payée)');
        });

        Schema::create('proprietaire_factures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_facture_id')->unique();
            $table->string('numero', 40);
            $table->date('emise_le');
            $table->string('libelle');
            $table->string('statut', 12)->comment('payee | a_payer | annulee');
            $table->decimal('montant', 10, 2)->comment('TTC CHF');
            $table->decimal('montant_ht', 10, 2)->nullable();
            $table->decimal('tva', 10, 2)->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('paiement_url')->nullable();
            $table->json('metadonnees')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'emise_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proprietaire_factures');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['formule', 'stripe_client_id', 'stripe_abonnement_id', 'stripe_moyen_paiement_id', 'moyen_paiement', 'statut_abonnement', 'periode_fin', 'resilie_le']);
        });
    }
};
