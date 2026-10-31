<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'envoi en cours d'une vidéo (31.10.2026, après la vérification) : une
 * adresse d'envoi ne se donne qu'après l'annonce de la durée et de la place
 * (upload/start), et un envoi commencé depuis trop longtemps se purge même
 * s'il redemande des adresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->timestamp('upload_started_at')->nullable()->after('files_status')->comment('Début de l’envoi en cours (upload/start)');
            $table->unsignedBigInteger('upload_expected_bytes')->nullable()->after('upload_started_at')->comment('Place annoncée au début de l’envoi');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['upload_started_at', 'upload_expected_bytes']);
        });
    }
};
