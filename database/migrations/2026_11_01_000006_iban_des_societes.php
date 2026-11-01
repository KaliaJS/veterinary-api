<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le compte de la société pour la QR-facture suisse (01.11.2026, choix
     * de Jim : la QR-facture aux normes SIX 2.3 plutôt que Stripe
     * Invoicing) : un IBAN suisse ou liechtensteinois, ou un QR-IBAN.
     */
    public function up(): void
    {
        Schema::table('societes', function (Blueprint $table) {
            $table->string('iban', 34)->nullable()->after('assujettie_tva')->comment('IBAN ou QR-IBAN (CH, LI), sans espaces');
        });
    }

    public function down(): void
    {
        Schema::table('societes', function (Blueprint $table) {
            $table->dropColumn('iban');
        });
    }
};
