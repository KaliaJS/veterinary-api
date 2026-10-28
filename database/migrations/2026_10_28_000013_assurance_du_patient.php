<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L'assurance de l'animal (décision de Jim, 28.10.2026) : saisie sur sa
 * fiche ; le règlement de ses rendez-vous s'y lit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('assurance', 120)->nullable()->after('puce');
        });
    }

    public function down(): void
    {
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn('assurance'));
    }
};
