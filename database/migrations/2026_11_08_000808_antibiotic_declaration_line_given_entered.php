<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026, vérification) : une quantité remise saisie par le
 * vétérinaire fait foi ; sans elle, elle suit la consommation totale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('antibiotic_declaration_lines', function (Blueprint $table): void {
            $table->boolean('is_given_entered')->default(false)->after('given_quantity')->comment('Quantité remise saisie par le vétérinaire');
        });
    }

    public function down(): void
    {
        Schema::table('antibiotic_declaration_lines', fn (Blueprint $table) => $table->dropColumn('is_given_entered'));
    }
};
