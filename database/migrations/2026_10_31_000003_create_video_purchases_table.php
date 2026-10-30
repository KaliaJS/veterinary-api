<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achats de vidéos (31.10.2026) : définitifs, sans remboursement, payés
 * sur le compte du vendeur ; Veterinary prélève 30 %. Gardés à vie, même
 * quand le vendeur retire la vidéo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->restrictOnDelete();
            // Le compte de l'acheteur effacé, la vente reste pour les comptes du vendeur
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('commission_cents')->comment('Part de Veterinary');
            $table->string('stripe_account_id')->comment('Compte du vendeur qui a encaissé');
            $table->string('payment_intent_id')->nullable()->unique();
            $table->timestamp('terms_accepted_at')->comment('Accès immédiat et achat définitif acceptés avant de payer');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['video_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_purchases');
    }
};
