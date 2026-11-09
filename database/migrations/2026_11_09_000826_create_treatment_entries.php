<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal des traitements d'une exploitation (OMédV art. 28 al. 1,
 * 09.11.2026) et les délais d'attente (art. 13) : ceux d'un article,
 * proposés dans une entrée ; l'entrée garde les siens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->unsignedSmallInteger('withdrawal_milk_days')->nullable()->comment('Délai d’attente, lait (jours)');
            $table->unsignedSmallInteger('withdrawal_meat_days')->nullable()->comment('Délai d’attente, viande et abats (jours)');
            $table->unsignedSmallInteger('withdrawal_eggs_days')->nullable()->comment('Délai d’attente, œufs (jours)');
        });

        Schema::create('treatment_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('herd_id')->nullable()->constrained('herds')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('animals', 255)->comment('Animaux ou groupe traités : marques auriculaires, nombre');
            $table->date('first_use_on');
            $table->date('last_use_on');
            $table->string('indication', 255);
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('product_name', 255)->comment('Dénomination commerciale');
            $table->string('quantity', 80);
            $table->unsignedSmallInteger('withdrawal_milk_days')->nullable();
            $table->unsignedSmallInteger('withdrawal_meat_days')->nullable();
            $table->unsignedSmallInteger('withdrawal_eggs_days')->nullable();
            $table->boolean('legal_defaults')->default(false)->comment('Délais légaux d’une reconversion (art. 13 al. 2 let. c)');
            $table->string('person_name', 120)->comment('Qui a prescrit, remis ou administré');
            $table->foreignId('farm_visit_id')->nullable()->constrained('farm_visits')->nullOnDelete();
            $table->foreignId('facture_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'last_use_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_entries');
        Schema::table('produits', fn (Blueprint $table) => $table->dropColumn(['withdrawal_milk_days', 'withdrawal_meat_days', 'withdrawal_eggs_days']));
    }
};
