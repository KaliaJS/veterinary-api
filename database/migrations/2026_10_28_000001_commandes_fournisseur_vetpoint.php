<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vetpoint, étapes 3 et 4 (28.10.2026) : une commande fournisseur envoyée
 * par Vetpoint garde son numéro Vetpoint, l'adresse et le type de livraison
 * choisis, la date souhaitée, et l'heure du dernier envoi tenté (une réponse
 * perdue se retrouve chez Vetpoint au lieu d'être renvoyée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table): void {
            $table->string('vetpoint_commande_id', 40)->nullable()->comment('Numéro de la commande chez Vetpoint');
            $table->string('vetpoint_adresse_id', 40)->nullable()->comment('Adresse de livraison du compte Vetpoint');
            $table->string('vetpoint_livraison', 10)->nullable()->comment('standard | express');
            $table->date('vetpoint_date_souhaitee')->nullable();
            $table->timestamp('vetpoint_envoi_le')->nullable()->comment('Envoi à Vetpoint tenté, réponse pas encore reçue');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table): void {
            $table->dropColumn(['vetpoint_commande_id', 'vetpoint_adresse_id', 'vetpoint_livraison', 'vetpoint_date_souhaitee', 'vetpoint_envoi_le']);
        });
    }
};
