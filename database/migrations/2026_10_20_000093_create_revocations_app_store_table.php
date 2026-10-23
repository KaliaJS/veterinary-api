<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Achats de l'App Store remboursés par Apple (23.10.2026) : gardés, pour
     * qu'une ancienne transaction signée renvoyée par l'app ne rende jamais
     * ce qui a été remboursé.
     */
    public function up(): void
    {
        Schema::create('revocations_app_store', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique()->comment('originalTransactionId d’Apple');
            $table->string('produit');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('revoquee_le');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revocations_app_store');
    }
};
