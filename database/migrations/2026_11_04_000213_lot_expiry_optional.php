<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un lot sans péremption (04.11.2026) : le matériel et les accessoires ont
 * un numéro de lot, pas toujours une date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->date('peremption_le')->nullable()->comment('Premier jour du mois de péremption, rendu « MM.YYYY » ; vide : sans péremption (matériel, accessoires)')->change();
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            // Impossible tant qu'un lot est sans péremption : il la garde vide
            $table->date('peremption_le')->comment('Premier jour du mois de péremption — rendu « MM.YYYY »')->change();
        });
    }
};
