<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages audio et vidéo partout (décision de Jim, 07.11.2026) : aussi
 * entre collègues (`staff_messages`) et entre cliniques
 * (`clinic_messages`). Un fichier appartient à un seul message, de l'un
 * des trois fils ; effacé avec lui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropForeign(['message_clientele_id']);
        });
        Schema::table('message_media', function (Blueprint $table): void {
            $table->unsignedBigInteger('message_clientele_id')->nullable()->change();
            $table->foreign('message_clientele_id')->references('id')->on('messages_clientele')->cascadeOnDelete();
            $table->foreignId('staff_message_id')->nullable()->unique()->after('message_clientele_id')->comment('Un message entre collègues')->constrained('staff_messages')->cascadeOnDelete();
            $table->foreignId('clinic_message_id')->nullable()->unique()->after('staff_message_id')->comment('Un message entre cliniques')->constrained('clinic_messages')->cascadeOnDelete();
        });
    }

    /**
     * Retour aux seuls fils des clients : les fichiers des fils internes
     * partent avec leurs colonnes. La clé étrangère et l'index unique
     * s'effacent avant la colonne (SQLite reconstruit la table et refuse un
     * index sur une colonne disparue).
     */
    public function down(): void
    {
        DB::table('message_media')->whereNull('message_clientele_id')->delete();
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropForeign(['clinic_message_id']);
            $table->dropForeign(['staff_message_id']);
        });
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropUnique(['clinic_message_id']);
            $table->dropUnique(['staff_message_id']);
        });
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropColumn(['clinic_message_id', 'staff_message_id']);
        });
        Schema::table('message_media', function (Blueprint $table): void {
            $table->dropForeign(['message_clientele_id']);
        });
        Schema::table('message_media', function (Blueprint $table): void {
            $table->unsignedBigInteger('message_clientele_id')->nullable(false)->change();
            $table->foreign('message_clientele_id')->references('id')->on('messages_clientele')->cascadeOnDelete();
        });
    }
};
