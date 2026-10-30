<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificats de visionnage (31.10.2026), pour la formation continue :
 * figés au moment où ils sont délivrés, vérifiables par leur code sur une
 * page publique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewing_certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('video_id')->constrained('videos')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('holder_name');
            $table->string('holder_gln', 13)->nullable();
            $table->string('video_title', 160);
            $table->string('seller_name');
            $table->decimal('duration_seconds', 10, 3);
            $table->timestamp('watched_at')->comment('Vue en entier le');
            $table->timestamps();
            $table->unique(['video_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_certificates');
    }
};
