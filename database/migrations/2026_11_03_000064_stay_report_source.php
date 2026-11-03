<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le rapport de la journée s'écrit aussi à la main, sans IA (03.11.2026) :
 * la feuille dit d'où vient le texte (ai ou manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stay_sheets', function (Blueprint $table): void {
            $table->string('report_source', 8)->nullable()->after('report_text')->comment('ai ou manual');
        });
    }

    public function down(): void
    {
        Schema::table('stay_sheets', function (Blueprint $table): void {
            $table->dropColumn('report_source');
        });
    }
};
