<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transcription des consultations (19.10.2026). Le vétérinaire enregistre
     * la consultation (l'accord du propriétaire est demandé oralement) ; l'app
     * envoie l'audio à la fin, en une ou plusieurs parties, par morceaux. Le
     * serveur le fait transcrire, efface l'audio, puis fait rédiger un
     * brouillon de compte rendu SOAP que le vétérinaire relit et valide.
     * Facturé à la minute transcrite, avec un plafond mensuel par organisation.
     * Transcription et brouillon sont chiffrés en base.
     */
    public function up(): void
    {
        Schema::create('enregistrements_consultation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rendez_vous_id')->constrained('rendez_vous')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('statut', 20)->default('enregistrement');
            $table->string('erreur', 20)->nullable();
            $table->unsignedSmallInteger('parties_attendues')->nullable();
            $table->unsignedTinyInteger('redactions')->default(0)->comment('Rédactions du compte rendu demandées (relances comprises), bornées (23.10.2026)');
            $table->longText('brouillon')->nullable();
            $table->timestamp('redige_le')->nullable();
            $table->timestamp('valide_le')->nullable();
            $table->timestamps();

            $table->index(['rendez_vous_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enregistrements_consultation');
    }
};
