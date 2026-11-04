<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les fournisseurs et centrales d'achat de la clinique (décisions de Jim,
 * 03.11.2026) : chacun son canal (une centrale par son connecteur, un bon en
 * PDF par e-mail, ou à la main), son minimum de commande, ses jours et son
 * heure de commande (la date butoir du panier automatique), ce qui se passe
 * sous le minimum à la date butoir, et s'il faut prévenir la veille.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('channel', 10)->default('manual')->comment('api : centrale par son connecteur ; email : bon en PDF par e-mail ; manual : à la main');
            $table->string('connector', 30)->nullable()->comment('Clé du connecteur de la centrale (config/purchasing.php), canal api seulement');
            $table->string('email')->nullable()->comment('Adresse des commandes, canal email');
            $table->decimal('minimum_order', 10, 2)->default(0)->comment('Minimum de commande CHF ; 0 : aucun');
            $table->json('order_days')->nullable()->comment('Jours de commande ISO (1 lundi … 7 dimanche)');
            $table->string('order_time', 5)->nullable()->comment('Heure limite HH:MM, à l’heure de la clinique');
            $table->string('under_minimum', 5)->default('ask')->comment('Sous le minimum à la date butoir : ask, send, wait');
            $table->boolean('notify_eve')->default(true)->comment('Prévenir la veille de la date butoir');
            $table->timestamps();

            $table->unique(['clinique_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
