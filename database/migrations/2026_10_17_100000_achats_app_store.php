<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Achats dans l'app iPhone (17.10.2026) : Plus et les objets de la planète
 * s'y paient par l'App Store (StoreKit 2), au prix du site majoré de 30 %.
 * Chaque compte a un identifiant App Store (UUID, appAccountToken des
 * transactions) ; l'abonnement Plus d'Apple vit à côté de celui de Stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('apple_compte')->nullable()->unique()->after('formule');
            $table->string('apple_abonnement_id')->nullable()->after('apple_compte');
            $table->timestamp('apple_fin')->nullable()->after('apple_abonnement_id');
            $table->boolean('apple_renouvelle')->nullable()->after('apple_fin');
        });

        Schema::table('achats_planete', function (Blueprint $table) {
            $table->string('apple_transaction_id')->nullable()->unique()->after('stripe_facture_id');
        });
    }

    public function down(): void
    {
        Schema::table('achats_planete', function (Blueprint $table) {
            $table->dropColumn('apple_transaction_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['apple_compte', 'apple_abonnement_id', 'apple_fin', 'apple_renouvelle']);
        });
    }
};
