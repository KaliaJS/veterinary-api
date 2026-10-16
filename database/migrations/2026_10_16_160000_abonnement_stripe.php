<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnement SaaS réel (16.10.2026) : client, abonnement et carte chez
 * Stripe Billing, factures reflétées depuis Stripe (numéro, statut, PDF).
 * Jusqu'ici la carte n'était qu'un libellé et rien n'était prélevé : les
 * libellés de carte existants sont effacés (aucune carte réelle derrière),
 * les anciennes factures « F- » restent en base mais ne sont plus servies
 * (sans facture Stripe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('stripe_client_id')->nullable()->unique()->after('moyen_paiement');
            $table->string('stripe_abonnement_id')->nullable()->unique()->after('stripe_client_id');
            $table->string('stripe_moyen_paiement_id')->nullable()->after('stripe_abonnement_id');
            $table->string('statut_abonnement', 12)->nullable()->after('stripe_moyen_paiement_id')
                ->comment('actif | impaye | incomplet | resilie ; null : aucun abonnement payant');
            $table->date('periode_fin')->nullable()->after('statut_abonnement')
                ->comment('Fin de la période payée : renouvellement, ou fin d’accès si résilié');
        });

        DB::table('organisations')->update(['moyen_paiement' => null]);

        Schema::table('organisation_factures', function (Blueprint $table): void {
            $table->string('stripe_facture_id')->nullable()->unique()->after('organisation_id');
            $table->string('statut', 12)->default('payee')->after('libelle')
                ->comment('payee | a_payer | annulee (miroir du statut Stripe)');
            $table->decimal('montant_ht', 10, 2)->nullable()->after('montant');
            $table->decimal('tva', 10, 2)->nullable()->after('montant_ht');
            $table->text('pdf_url')->nullable()->after('tva');
            $table->text('paiement_url')->nullable()->after('pdf_url')->comment('Page Stripe pour régler une facture à payer');
            $table->json('metadonnees')->nullable()->after('paiement_url');
            $table->boolean('quota_applique')->default(false)->after('metadonnees')
                ->comment('Pack de quota : quantité ajoutée une seule fois, au paiement');
            $table->string('numero', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('organisation_factures', function (Blueprint $table): void {
            $table->dropColumn(['stripe_facture_id', 'statut', 'montant_ht', 'tva', 'pdf_url', 'paiement_url', 'metadonnees', 'quota_applique']);
        });
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn(['stripe_client_id', 'stripe_abonnement_id', 'stripe_moyen_paiement_id', 'statut_abonnement', 'periode_fin']);
        });
    }
};
