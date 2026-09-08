<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Réglages de l'espace client (onglets Notifications, Langue & région,
 * Confidentialité du proto) et notifications marquées lues — un
 * enregistrement par compte, créé aux défauts à la première lecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferences_proprietaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('rappels_email')->default(true);
            $table->boolean('rappels_sms')->default(false);
            $table->boolean('reponses_clinique')->default(true);
            $table->boolean('nouveautes_boutique')->default(false);
            $table->boolean('statistiques_anonymes')->default(true);
            $table->string('langue', 5)->default('fr');
            $table->json('notifications_lues')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preferences_proprietaire');
    }
};
