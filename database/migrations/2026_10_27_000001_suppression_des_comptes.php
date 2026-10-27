<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression du compte depuis l'app (App Store, décisions de Jim,
 * 27.10.2026) : la date de la demande (effacement 30 jours plus tard,
 * annulé par une reconnexion), et la clinique qui garde le fil de messages
 * et les demandes de renouvellement d'ordonnance, détachés du compte
 * effacé au lieu d'être effacés avec lui. Première migration après la mise
 * en production : les migrations successives reprennent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('suppression_demandee_le')->nullable()
                ->comment('Suppression du compte demandée (effacé 30 jours après, annulée par une reconnexion)');
            $table->index('suppression_demandee_le');
        });

        foreach (['messages_clientele', 'demandes_renouvellement_ordonnance'] as $nom) {
            Schema::table($nom, function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
            });
            Schema::table($nom, function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['messages_clientele', 'demandes_renouvellement_ordonnance'] as $nom) {
            Schema::table($nom, function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
            });
            Schema::table($nom, function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['suppression_demandee_le']);
            $table->dropColumn('suppression_demandee_le');
        });
    }
};
