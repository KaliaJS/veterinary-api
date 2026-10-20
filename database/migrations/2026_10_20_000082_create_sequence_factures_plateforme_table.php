<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Abonnements et factures maison (20.10.2026) : Stripe ne sert plus qu'à
     * enregistrer les cartes et à les prélever (plus de Stripe Billing, 0,7 %
     * du volume économisé). Chaque abonné (organisation, compte propriétaire
     * pour Plus) a sa période en cours, renouvelée à la date anniversaire comme
     * chez Netflix ou Claude ; un paiement refusé est retenté quelques jours plus
     * tard. Les factures sont les nôtres (numéro MS-AAAA-NNNNN, lignes, période,
     * paiement), sans TVA : Meteora Systems Sàrl n'y est pas assujettie.
     *
     * `stripe_abonnement_id` est gardé le temps d'annuler les anciens
     * abonnements Stripe Billing de test ; il n'est plus lu.
     */
    public function up(): void
    {
        Schema::create('sequence_factures_plateforme', function (Blueprint $table) {
            $table->unsignedSmallInteger('annee')->primary();
            $table->unsignedInteger('prochain')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_factures_plateforme');
    }
};
