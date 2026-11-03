<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier patient (03.11.2026) : les principes actifs de chaque médicament
 * du catalogue Swissmedic (OGD-Deklarationen, catégorie WIRKS), en nom
 * latin (OGD-Stoff-Synonyme). Ils relient une molécule notée sur la fiche
 * (« méloxicam ») à la marque prescrite (Metacam).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits_veterinaires', function (Blueprint $table): void {
            $table->json('substances')->nullable()->comment('Principes actifs, nom latin du flux Swissmedic (meloxicamum…)');
        });
    }

    public function down(): void
    {
        Schema::table('produits_veterinaires', function (Blueprint $table): void {
            $table->dropColumn('substances');
        });
    }
};
