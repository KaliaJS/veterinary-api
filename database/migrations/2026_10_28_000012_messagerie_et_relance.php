<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Réglages « Rappels & messages » branchés (décision de Jim, 28.10.2026) :
 * relance après un no-show, réception des messages des clients, réponses
 * seulement. Par défaut, rien ne change : la clinique reçoit les messages,
 * le propriétaire peut écrire, aucune relance ne part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliniques', function (Blueprint $table): void {
            $table->boolean('relance_noshow')->default(false);
            $table->boolean('messages_recus')->default(true);
            $table->boolean('messages_reponses_seulement')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('cliniques', fn (Blueprint $table) => $table->dropColumn(['relance_noshow', 'messages_recus', 'messages_reponses_seulement']));
    }
};
