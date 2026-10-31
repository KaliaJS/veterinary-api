<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les conditions de vente des vidéos de formation, acceptées par le
 * vétérinaire qui vend en son nom (31.10.2026, demande de Jim) : la version
 * acceptée et sa date ; une nouvelle version se réaccepte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('video_terms_version', 20)->nullable()->comment('Version des conditions de vente des vidéos acceptée');
            $table->timestamp('video_terms_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['video_terms_version', 'video_terms_accepted_at']);
        });
    }
};
