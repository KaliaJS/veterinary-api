<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026, vérification) : une quantité totale saisie par le
 * vétérinaire fait foi ; sans elle, la formule de l'OSAV la recalcule à
 * chaque enregistrement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('antibiotic_declaration_lines', function (Blueprint $table): void {
            $table->boolean('is_total_entered')->default(false)->after('total_quantity')->comment('Quantité totale saisie par le vétérinaire, pas calculée');
        });
    }

    public function down(): void
    {
        Schema::table('antibiotic_declaration_lines', fn (Blueprint $table) => $table->dropColumn('is_total_entered'));
    }
};
