<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression du compte, suite (vérification du 27.10.2026) : les factures
 * Plus de Meteora sont des pièces comptables (CO 958f, 10 ans) ; elles
 * restent, détachées du compte effacé, avec le nom et l'adresse du
 * titulaire. Le fil de messages gardé par la clinique reste attribuable :
 * sa fiche client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proprietaire_factures', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::table('proprietaire_factures', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('titulaire_nom')->nullable()->comment('Nom du titulaire, gardé quand son compte est effacé');
            $table->string('titulaire_email')->nullable()->comment('Adresse du titulaire, gardée quand son compte est effacé');
        });

        Schema::table('messages_clientele', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('user_id')
                ->comment('Fiche client du fil, posée quand le compte est effacé')
                ->constrained('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages_clientele', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
        });

        // Les factures d'un compte effacé (pièces comptables, user_id vide)
        // restent : la colonne reste nullable, seule la cascade revient
        Schema::table('proprietaire_factures', function (Blueprint $table): void {
            $table->dropColumn(['titulaire_nom', 'titulaire_email']);
            $table->dropForeign(['user_id']);
        });
        Schema::table('proprietaire_factures', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
