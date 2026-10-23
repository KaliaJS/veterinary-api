<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('societes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('ide')->nullable()->comment('N° IDE suisse « CHE-… »');
            $table->string('stripe_compte_id')->nullable()->unique()->comment('Compte de paiement de la société (Stripe Connect sans tableau de bord, risque chez Stripe) : terminal, TWINT et acomptes encaissés à son nom');
            $table->boolean('paiements_actifs')->default(false)->comment('Le compte peut encaisser');
            $table->boolean('virements_actifs')->default(false)->comment('Le compte reçoit ses virements');
            $table->boolean('paiements_a_completer')->default(false)->comment('Des informations sont demandées pour garder le compte ouvert');
            $table->timestamp('paiements_maj_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('societes');
    }
};
