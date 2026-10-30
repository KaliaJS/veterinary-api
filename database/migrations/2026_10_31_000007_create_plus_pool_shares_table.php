<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La part de chaque vendeur dans la cagnotte d'un mois (31.10.2026).
 * Versée quand ce qui lui est dû atteint 10 CHF, et au plus tard à la fin
 * de l'année, quel que soit le montant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plus_pool_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plus_pool_month_id')->constrained('plus_pool_months')->restrictOnDelete();
            $table->foreignId('seller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('seller_clinique_id')->nullable()->constrained('cliniques')->restrictOnDelete();
            $table->unsignedInteger('views');
            $table->unsignedInteger('earned_cents');
            $table->string('stripe_account_id')->nullable()->comment('Compte qui a reçu le versement');
            $table->string('transfer_id')->nullable()->comment('Versement du prestataire');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['paid_at', 'seller_user_id']);
            $table->index(['paid_at', 'seller_clinique_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plus_pool_shares');
    }
};
