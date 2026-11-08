<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le mode silencieux d'une conversation (décision de Jim, 08.11.2026),
 * façon WhatsApp : pour une personne, sur tous ses appareils. Plus de
 * notification sur le téléphone, et la conversation ne compte plus dans
 * la pastille du menu. `thread` est la clé du fil vue par cette personne
 * (« u12 », « e5 », « k3 ») ; `clinique_id` dit dans quelle clinique,
 * sauf entre collègues (« e… »), où le fil suit la personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('thread', 24)->comment('Clé du fil vue par cette personne : u12, c34, e5, k3');
            $table->timestamps();

            $table->unique(['user_id', 'clinique_id', 'thread']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_mutes');
    }
};
