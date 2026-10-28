<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Note de l'accueil d'un rendez-vous (décision de Jim, 28.10.2026) : posée
 * à la prise du rendez-vous, modifiable depuis la fiche, interne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table): void {
            $table->text('note_accueil')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table): void {
            $table->dropColumn('note_accueil');
        });
    }
};
