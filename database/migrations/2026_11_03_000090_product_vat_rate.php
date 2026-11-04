<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TVA par ligne (décision de Jim, 03.11.2026) : le taux de vente de
 * chaque article, réduit ou normal (les pourcentages sont dans
 * config/vat.php). Les articles existants le reçoivent selon leur
 * famille : médicaments, antiparasitaires, phytothérapie et alimentation
 * au taux réduit, le reste au taux normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->enum('vat_rate', ['normal', 'reduced'])->default('normal')->after('prix')
                ->comment('Taux de TVA à la vente : reduced ou normal, proposé selon la famille et corrigible');
        });

        DB::table('produits')->whereIn('categorie', ['med', 'apara', 'phyto', 'ali'])->update(['vat_rate' => 'reduced']);
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->dropColumn('vat_rate');
        });
    }
};
