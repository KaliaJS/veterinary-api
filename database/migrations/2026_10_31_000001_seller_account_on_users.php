<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vidéos de formation (décision de Jim, 31.10.2026) : un vétérinaire qui
 * vend en son nom encaisse sur son propre compte de paiement (Stripe
 * Connect, sans tableau de bord), comme une société pour une clinique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_account_id')->nullable()->unique()->comment('Compte de paiement du vendeur de vidéos');
            $table->timestamp('stripe_account_ready_at')->nullable()->comment('Le compte peut encaisser');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn(['stripe_account_id', 'stripe_account_ready_at']);
        });
    }
};
