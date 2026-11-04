<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TVA par ligne (décision de Jim, 03.11.2026) : chaque ligne de facture
 * garde son taux, figé à l'émission, et le contexte qui l'a décidé (un
 * soin suit l'espèce, un article remis ou vendu son taux de vente). Les
 * lignes d'avant restent sans taux : elles prennent le taux unique de
 * leur facture, figé lui aussi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facture_lignes', function (Blueprint $table): void {
            $table->decimal('taux_tva', 3, 1)->nullable()->after('prix_chf')
                ->comment('Taux de TVA de la ligne, figé à l’émission, en % ; null : celui de la facture (lignes d’avant le 03.11.2026)');
            $table->enum('vat_context', ['care', 'sale'])->nullable()->after('taux_tva')
                ->comment('care : soin, ou article donné pendant le soin ; sale : article remis au propriétaire ou vendu');
        });
    }

    public function down(): void
    {
        Schema::table('facture_lignes', function (Blueprint $table): void {
            $table->dropColumn(['taux_tva', 'vat_context']);
        });
    }
};
