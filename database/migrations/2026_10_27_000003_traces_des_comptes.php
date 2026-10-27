<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace d'un ancien membre d'équipe qui a supprimé son compte (décision de
 * Jim, 27.10.2026) : la date à laquelle le compte est devenu une trace. Sa
 * fiche d'équipe ne peut plus être rétablie (28.10.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('efface_le')->nullable()->comment('Compte devenu une trace sans connexion (ancien membre d’équipe)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('efface_le');
        });
    }
};
