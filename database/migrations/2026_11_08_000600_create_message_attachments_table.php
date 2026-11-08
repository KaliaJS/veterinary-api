<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les pièces jointes des messages (décision de Jim, 08.11.2026) : photos et
 * PDF, jusqu'à dix par message, dans le fil d'un client, entre collègues et
 * entre cliniques (un seul des trois messages). Une photo est rangée
 * réencodée, sans ses métadonnées (position GPS comprise), avec son
 * aperçu ; `clinique_id` : la clinique où le fichier est rangé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_clientele_id')->nullable()->constrained('messages_clientele')->cascadeOnDelete();
            $table->foreignId('staff_message_id')->nullable()->constrained('staff_messages')->cascadeOnDelete();
            $table->foreignId('clinic_message_id')->nullable()->constrained('clinic_messages')->cascadeOnDelete();
            // Effacée, la clinique ne doit pas emporter les pièces d'une conversation entre collègues (qui appartient à l'organisation)
            $table->foreignId('clinique_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('position')->default(0)->comment('Ordre dans le message');
            $table->string('kind', 8)->comment('image ou pdf');
            $table->string('name', 160)->comment('Nom montré dans la bulle');
            $table->string('mime', 40);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('path')->nullable()->comment('Sur le disque privé ; vide quand le fichier a été effacé avec le compte');
            $table->string('thumb_path')->nullable()->comment('Aperçu d’une photo');
            $table->timestamp('erased_at')->nullable()->comment('Fichier effacé avec le compte de son auteur');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
