<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapport de bloc, 2e vérification (09.11.2026) : les rubriques que le
 * vétérinaire a corrigées (un nouveau brouillon ne les réécrit jamais, même
 * vidées exprès), et une dictée arrivée pendant une rédaction (un nouveau
 * brouillon suit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_reports', function (Blueprint $table): void {
            $table->json('edited_sections')->nullable()->after('sections')->comment('Rubriques corrigées par le vétérinaire');
            $table->boolean('is_redraft_pending')->default(false)->after('writings')->comment('Une dictée arrivée pendant la rédaction');
        });
    }

    public function down(): void
    {
        Schema::table('surgery_reports', function (Blueprint $table): void {
            $table->dropColumn(['edited_sections', 'is_redraft_pending']);
        });
    }
};
