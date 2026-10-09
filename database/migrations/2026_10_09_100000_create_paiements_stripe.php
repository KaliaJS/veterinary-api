<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements en caisse par Stripe (docs/data-model.md §6) :
 * - la clinique porte sa « location » Stripe Terminal (créée au premier
 *   lecteur appairé) ;
 * - les terminaux de paiement appairés à la clinique ;
 * - les paiements (une intention Stripe par tentative : TPE ou TWINT) ;
 * - les événements webhook déjà traités (déduplication par id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliniques', function (Blueprint $table): void {
            $table->string('stripe_location_id')->nullable()->after('nombre_boxes');
        });

        Schema::create('terminaux_paiement', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinique_id')->constrained('cliniques')->cascadeOnDelete();
            $table->string('stripe_reader_id')->unique();
            $table->string('libelle');
            $table->string('modele')->nullable();
            $table->string('numero_serie')->nullable();
            $table->timestamps();
        });

        Schema::create('paiements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained('cliniques')->cascadeOnDelete();
            $table->string('moyen');
            $table->decimal('montant', 10, 2);
            $table->string('stripe_payment_intent_id')->unique();
            $table->foreignId('terminal_paiement_id')->nullable()->constrained('terminaux_paiement')->nullOnDelete();
            $table->string('statut')->default('en_attente');
            $table->string('erreur')->nullable();
            $table->text('url_twint')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reussi_le')->nullable();
            $table->timestamps();

            $table->index(['facture_id', 'statut']);
        });

        Schema::create('evenements_stripe', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('traite_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evenements_stripe');
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('terminaux_paiement');

        Schema::table('cliniques', function (Blueprint $table): void {
            $table->dropColumn('stripe_location_id');
        });
    }
};
