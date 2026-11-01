<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quand un rendez-vous a été déplacé pour la dernière fois (décision de
 * Jim, 01.11.2026) : déplacé la veille au soir au lendemain, après le
 * dernier passage des rappels, il est rappelé le matin même, comme un
 * rendez-vous pris la veille au soir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table): void {
            $table->dateTime('deplace_le')->nullable()->after('ends_at')->comment('Dernier changement de l’heure de début');
        });
    }

    public function down(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table): void {
            $table->dropColumn('deplace_le');
        });
    }
};
