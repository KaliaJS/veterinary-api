<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le virement de la cagnotte en cours (31.10.2026, sixième vérification) :
 * noté avant l'appel au prestataire. Une réponse perdue se retrouve ensuite
 * par ce groupe, et rien ne se verse deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plus_pool_shares', function (Blueprint $table): void {
            $table->string('payout_group')->nullable()->after('transfer_id')->comment('Groupe du virement envoyé, retrouvé si la réponse se perd')->index();
        });
    }

    public function down(): void
    {
        Schema::table('plus_pool_shares', function (Blueprint $table): void {
            $table->dropIndex(['payout_group']);
            $table->dropColumn('payout_group');
        });
    }
};
