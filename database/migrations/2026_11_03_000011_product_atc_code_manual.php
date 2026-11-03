<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier patient (03.11.2026) : le code ATC d'un article saisi à la main,
 * même vide. Un code effacé exprès ne revient pas du catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->boolean('atc_code_is_manual')->default(false)->comment('Code ATC saisi ou effacé à la main : jamais repris du catalogue');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->dropColumn('atc_code_is_manual');
        });
    }
};
