<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partager le dossier médical (demande de Jim, 09.11.2026) : un lien
 * provisoire vers les pièces choisies, ouvert sans compte par un confrère,
 * ou un export en PDF fait par la clinique (même chemin, sans destinataire).
 * Le jeton n'est gardé que haché. Chaque ouverture et chaque téléchargement
 * complet est noté ; un lien se coupe à tout moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8)->comment('link, export');
            $table->string('token_hash', 64)->unique()->comment('SHA-256 du jeton du lien');
            $table->string('recipient_email')->nullable();
            $table->json('items')->comment('Les pièces choisies : [{type, id}]');
            $table->boolean('include_visit_notes')->default(false)->comment('Les notes des visites partent aussi (choix du vétérinaire)');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('created_by_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->unsignedSmallInteger('full_downloads')->default(0);
            $table->timestamps();
            $table->index(['clinique_id', 'kind', 'created_at']);
            $table->index(['patient_id', 'kind']);
        });

        Schema::create('record_share_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_share_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->comment('opened, downloaded');
            $table->timestamp('at');
            $table->index(['record_share_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_share_events');
        Schema::dropIfExists('record_shares');
    }
};
