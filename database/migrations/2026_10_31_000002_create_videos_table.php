<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vidéos de formation (décision de Jim, 31.10.2026) : vendues par un
 * vétérinaire en son nom ou par une clinique. Les fichiers (HLS en 1080p
 * au plus et 720p, segments CMAF) vivent dans le bucket sous
 * `videos/{uuid}/` ; le 4K d'origine n'est jamais gardé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->comment('Dans les chemins du bucket et les adresses');
            // Vendeur : un vétérinaire en son nom, ou une clinique (jamais les deux)
            // Un vendeur qui efface son compte ne retire rien aux acheteurs : la vidéo reste
            $table->foreignId('seller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('seller_clinique_id')->nullable()->constrained('cliniques')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->comment('Clinique vendeuse : son stockage compte ici')->constrained('organisations')->restrictOnDelete();
            $table->string('seller_name')->comment('Nom du vendeur montré aux acheteurs, figé à la publication');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents')->nullable()->comment('2 CHF au moins, sans maximum');
            $table->string('audience', 10)->nullable()->comment('vets, owners ou both');
            $table->boolean('in_plus')->default(false)->comment('Incluse dans Plus (seulement si les clients en sont)');
            $table->string('status', 12)->default('draft')->comment('draft, published, withdrawn');
            $table->string('files_status', 12)->default('pending')->comment('pending, ready, rejected');
            $table->string('rejection_reason')->nullable();
            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0)->comment('Tous les fichiers de la vidéo, comptés dans le stockage');
            $table->json('renditions')->nullable()->comment('Qualités vérifiées : liste, largeur, hauteur, débit');
            $table->string('download_path')->nullable()->comment('MP4 720p à télécharger, assemblé par le serveur');
            $table->timestamp('files_verified_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'audience']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
