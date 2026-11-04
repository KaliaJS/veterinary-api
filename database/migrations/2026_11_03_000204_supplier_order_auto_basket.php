<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le panier automatique (03.11.2026) : un brouillon marqué automatique, un
 * par fournisseur et par clinique, avec sa date butoir, sa pause, ce qui a
 * été dit à la clinique (la veille, sous le minimum), ce qui est passé chez
 * un autre fournisseur, et la dernière panne d'envoi. L'adresse e-mail où
 * un bon est parti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_auto')->default(false)->comment('Panier automatique');
            $table->timestamp('deadline_at')->nullable()->comment('Date butoir visée par le panier automatique');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('eve_notice_for')->nullable()->comment('Date butoir dont la veille a été annoncée');
            $table->timestamp('decision_asked_for')->nullable()->comment('Date butoir (ou minimum) pour laquelle la clinique a été interrogée');
            $table->json('moved_out')->nullable()->comment('Articles passés chez un autre fournisseur : [{produit_id, name, to}]');
            $table->string('auto_error')->nullable()->comment('Pourquoi le panier n’est pas parti seul');
            $table->string('sent_to')->nullable()->comment('Adresse e-mail où le bon est parti');

            $table->index(['clinique_id', 'supplier_id', 'is_auto', 'statut'], 'cmd_fourn_auto_basket_index');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_fournisseur', function (Blueprint $table): void {
            $table->dropIndex('cmd_fourn_auto_basket_index');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['is_auto', 'deadline_at', 'paused_at', 'eve_notice_for', 'decision_asked_for', 'moved_out', 'auto_error', 'sent_to']);
        });
    }
};
