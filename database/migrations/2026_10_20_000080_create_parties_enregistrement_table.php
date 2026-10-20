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
        Schema::create('parties_enregistrement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enregistrement_consultation_id')->constrained('enregistrements_consultation')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('statut', 20)->default('envoi');
            $table->string('format', 40);
            $table->unsignedSmallInteger('morceaux');
            $table->string('fichier')->nullable();
            $table->unsignedInteger('duree_secondes')->nullable();
            $table->longText('segments')->nullable();
            $table->string('requete_fournisseur')->nullable();
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->timestamp('recue_le')->nullable();
            $table->timestamp('transcrite_le')->nullable();
            $table->timestamps();

            $table->unique(['enregistrement_consultation_id', 'numero'], 'parties_enregistrement_numero_unique');
            $table->index(['statut', 'transcrite_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties_enregistrement');
    }
};
