<?php

use App\Enums\FormuleSaas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Abonnement SaaS de l'organisation (docs/data-model.md §2) :
     * formule, cycle, résiliation fin de mois, moyen de paiement
     * (libellé masqué), compteurs d'usage du mois et packs de quota
     * achetés (non reportés, sauf le stockage — permanent).
     */
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->enum('plan', array_column(FormuleSaas::cases(), 'value'))
                ->default(FormuleSaas::Cabinet->value)->after('nom');
            $table->enum('cycle', ['mois', 'an'])->default('mois')->after('plan');
            $table->date('resilie_le')->nullable()->after('cycle')
                ->comment('Fin de l’abonnement résilié — réactivable jusque-là');
            $table->string('moyen_paiement')->nullable()->after('resilie_le')
                ->comment('Libellé masqué « Visa •••• 4242 »');
            $table->unsignedInteger('usage_emails')->default(0)->after('moyen_paiement');
            $table->unsignedInteger('usage_sms')->default(0)->after('usage_emails');
            $table->decimal('usage_stockage_go', 6, 1)->default(0)->after('usage_sms');
            $table->unsignedInteger('quota_extra_emails')->default(0)->after('usage_stockage_go');
            $table->unsignedInteger('quota_extra_sms')->default(0)->after('quota_extra_emails');
            $table->unsignedInteger('quota_extra_stockage_go')->default(0)->after('quota_extra_sms');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn([
                'plan', 'cycle', 'resilie_le', 'moyen_paiement',
                'usage_emails', 'usage_sms', 'usage_stockage_go',
                'quota_extra_emails', 'quota_extra_sms', 'quota_extra_stockage_go',
            ]);
        });
    }
};
