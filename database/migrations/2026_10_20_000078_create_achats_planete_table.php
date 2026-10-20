<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jeu de la planète (16.10.2026) : la date de décès d'un animal (une petite
     * tombe à son nom sur la planète ; les rappels de la clinique s'arrêtent),
     * et les objets achetés dans la boutique de la planète, une fois chacun.
     */
    public function up(): void
    {
        Schema::create('achats_planete', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('article', 20);
            $table->string('stripe_facture_id')->nullable();
            $table->string('apple_transaction_id')->nullable();
            $table->timestamps();

            $table->unique('apple_transaction_id');
            $table->unique(['user_id', 'article']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achats_planete');
    }
};
