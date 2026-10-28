<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Téléconsultation maison (WebRTC, 28.10.2026) : le client rejoint par un
 * lien secret propre à la session, envoyé par e-mail. Seule son empreinte
 * sert à la retrouver, le lien est gardé chiffré pour le renvoyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_teleconsultation', function (Blueprint $table): void {
            $table->string('jeton_client', 64)->nullable()->unique();
            $table->text('jeton_client_chiffre')->nullable();
            $table->dateTime('lien_envoye_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sessions_teleconsultation', function (Blueprint $table): void {
            $table->dropUnique(['jeton_client']);
            $table->dropColumn(['jeton_client', 'jeton_client_chiffre', 'lien_envoye_le']);
        });
    }
};
