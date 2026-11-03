<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier patient, proposition B (03.11.2026) : le code ATC (ou ATCvet)
 * d'un médicament du stock. Il dit à quelle famille appartient le produit
 * (pénicillines, AINS…) pour mettre en garde quand l'animal y est
 * allergique. Saisi à la création de l'article, ou repris du catalogue
 * Swissmedic quand le code-barres y est connu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->string('atc_code', 12)->nullable()->comment('Code ATC ou ATCvet (QM01AC06…), famille du médicament');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->dropColumn('atc_code');
        });
    }
};
