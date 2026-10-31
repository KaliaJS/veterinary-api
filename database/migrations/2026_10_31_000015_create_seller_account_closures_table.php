<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les comptes de paiement des vendeurs effacés, à fermer chez le
 * prestataire (31.10.2026, douzième vérification) : une tâche de nuit les
 * ferme quand plus aucun paiement n'y est en cours et que leur solde est
 * versé, et signale ceux qui résistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_account_closures', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_account_id')->unique()->comment('Compte de paiement du vendeur effacé');
            $table->timestamp('requested_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('reported_at')->nullable()->comment('Signalé pour une fermeture à la main');
            $table->timestamps();
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_account_closures');
    }
};
