<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La durée que Deepgram a réellement traitée (deuxième vérification,
 * 07.11.2026), à part de celle refacturée à la clinique (plafonnée) : le
 * budget Deepgram de la plateforme compte ce qui est payé, quoi que dise le
 * fichier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_media', function (Blueprint $table): void {
            $table->unsignedInteger('provider_seconds')->nullable()->after('transcript_seconds')->comment('Durée traitée et payée chez Deepgram');
            $table->timestamp('provider_at')->nullable()->after('provider_seconds')->comment('Réponse de Deepgram');
        });
    }

    public function down(): void
    {
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropColumn(['provider_seconds', 'provider_at']);
        });
    }
};
