<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages audio et vidéo façon Telegram (demande de Jim, 04.11.2026 ; feu
 * vert du 06.11.2026) : le fichier d'un message du fil d'un client, sur le
 * disque privé, avec sa durée et son onde servies telles qu'enregistrées.
 * La transcription « →A » se fait une fois, à la demande de l'équipe de
 * la clinique : son texte chiffré, la durée facturée (comme une partie de
 * consultation) et l'organisation qui la paie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_clientele_id')->unique()->constrained('messages_clientele')->cascadeOnDelete();
            $table->foreignId('clinique_id')->comment('La clinique du fil : seule son équipe lit le fichier')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->comment('audio ou video (ronde, une minute au plus)');
            $table->string('path')->comment('Sur le disque privé');
            $table->string('mime', 40)->comment('Le conteneur reconnu à ses premiers octets : audio/webm, audio/mp4, audio/ogg, video/webm, video/mp4');
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('duration_ms');
            $table->json('peaks')->comment('Les barres de l’onde, de 0 à 1');
            $table->string('transcript_status', 10)->nullable()->comment('pending, done ou failed ; vide : jamais demandée');
            $table->text('transcript')->nullable()->comment('Chiffré');
            $table->unsignedInteger('transcript_seconds')->nullable()->comment('Durée transcrite facturée');
            $table->timestamp('transcribed_at')->nullable();
            $table->foreignId('transcript_organisation_id')->nullable()->comment('L’organisation qui paie la transcription')->constrained('organisations')->nullOnDelete();
            $table->foreignId('transcript_requested_by')->nullable()->constrained('employes')->nullOnDelete();
            $table->string('transcript_request_id')->nullable()->comment('Identifiant de la requête chez Deepgram');
            $table->unsignedTinyInteger('transcript_attempts')->default(0);
            $table->timestamps();

            $table->index(['transcript_organisation_id', 'transcribed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_media');
    }
};
