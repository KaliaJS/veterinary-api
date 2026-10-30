<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visionnages (31.10.2026) : une ligne par personne et par vidéo. Une vue
 * compte à plus d'une minute regardée, une seule fois ; la vidéo est vue en
 * entier à 95 %, ce qui ouvre le certificat de visionnage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('watched_ranges')->nullable()->comment('Secondes regardées, en intervalles fusionnés');
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('counted_at')->nullable()->comment('Plus d’une minute regardée : la vue compte');
            $table->boolean('counted_via_plus')->default(false)->comment('Regardée grâce à Plus : compte pour la cagnotte');
            $table->timestamp('completed_at')->nullable()->comment('Vue à 95 % : certificat possible');
            $table->timestamps();
            $table->unique(['video_id', 'user_id']);
            $table->index(['counted_via_plus', 'counted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_views');
    }
};
