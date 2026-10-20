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
        Schema::create('facturations_transcription', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->date('periode_debut');
            $table->date('periode_fin')->nullable();
            $table->unsignedInteger('secondes');
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('montant_centimes');
            $table->foreignId('organisation_facture_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'periode_debut'], 'facturations_transcription_organisation_id_mois_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturations_transcription');
    }
};
