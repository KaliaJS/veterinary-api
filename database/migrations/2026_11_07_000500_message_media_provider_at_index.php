<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le budget Deepgram de la plateforme se lit par mois sur `provider_at`
 * (troisième vérification, 07.11.2026) : un index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_media', function (Blueprint $table): void {
            $table->index('provider_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropIndex(['provider_at']);
        });
    }
};
