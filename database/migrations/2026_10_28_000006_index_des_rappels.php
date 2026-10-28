<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rappels (28.10.2026) : la recherche horaire des échéances et des
 * contrôles, toutes cliniques confondues, passe par un index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccinations', function (Blueprint $table): void {
            $table->index('echeance_le');
        });
        Schema::table('rendez_vous', function (Blueprint $table): void {
            $table->index(['type', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('vaccinations', function (Blueprint $table): void {
            $table->dropIndex(['echeance_le']);
        });
        Schema::table('rendez_vous', function (Blueprint $table): void {
            $table->dropIndex(['type', 'status', 'starts_at']);
        });
    }
};
