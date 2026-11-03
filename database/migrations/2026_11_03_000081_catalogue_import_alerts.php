<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue (03.11.2026) : l'heure de l'alerte envoyée par e-mail pour un
 * import en échec ou en avertissement. Une seule alerte par jour et par
 * source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_imports', function (Blueprint $table): void {
            $table->timestamp('alert_sent_at')->nullable()->comment('Alerte par e-mail envoyée (échec ou avertissement), une par jour');
        });
    }

    public function down(): void
    {
        Schema::table('catalogue_imports', function (Blueprint $table): void {
            $table->dropColumn('alert_sent_at');
        });
    }
};
