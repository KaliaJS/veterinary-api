<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026, vérification) : le complément de l'IDE sous lequel la
 * déclaration est partie ; sa désactivation part sous le même, même si la
 * clinique a changé de complément depuis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('antibiotic_declarations', function (Blueprint $table): void {
            $table->string('sent_appendix', 100)->nullable()->after('sent_at')->comment('Complément de l’IDE à l’envoi');
        });
    }

    public function down(): void
    {
        Schema::table('antibiotic_declarations', fn (Blueprint $table) => $table->dropColumn('sent_appendix'));
    }
};
