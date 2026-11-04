<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'avis part toujours, au moins 12 heures avant chaque départ (décision de
 * Jim, 04.11.2026) : le réglage « prévenir la veille » du fournisseur n'a
 * plus d'usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('notify_eve');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->boolean('notify_eve')->default(true)->comment('Prévenir la veille de la date butoir');
        });
    }
};
