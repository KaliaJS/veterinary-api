<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les conditions de vente des vidéos de formation, acceptées pour une
 * clinique par la personne qui publie (31.10.2026, demande de Jim) : la
 * version, la date et qui les a acceptées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('video_terms_version', 20)->nullable()->comment('Version des conditions de vente des vidéos acceptée');
            $table->timestamp('video_terms_accepted_at')->nullable();
            $table->foreignId('video_terms_accepted_by')->nullable()->comment('Personne qui les a acceptées pour l’organisation')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('video_terms_accepted_by');
            $table->dropColumn(['video_terms_version', 'video_terms_accepted_at']);
        });
    }
};
