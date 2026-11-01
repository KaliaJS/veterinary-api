<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La date du formulaire officiel d'une remise du tableau a (décision de Jim,
 * 01.11.2026) : il est valable un mois (art. 47, al. 2 OCStup).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->date('ordonnance_le')->nullable()->after('ordonnance_numero');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->dropColumn('ordonnance_le');
        });
    }
};
