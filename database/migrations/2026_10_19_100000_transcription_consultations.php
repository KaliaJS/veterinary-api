<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Transcription des consultations (19.10.2026). Le vétérinaire enregistre
 * la consultation (l'accord du propriétaire est demandé oralement) ; l'app
 * envoie l'audio à la fin, en une ou plusieurs parties, par morceaux. Le
 * serveur le fait transcrire, efface l'audio, puis fait rédiger un
 * brouillon de compte rendu SOAP que le vétérinaire relit et valide.
 * Facturé à la minute transcrite, avec un plafond mensuel par organisation.
 * Transcription et brouillon sont chiffrés en base.
 */
return new class extends Migration
{
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
            $table->longText('brouillon')->nullable();
            $table->timestamp('redige_le')->nullable();
            $table->timestamp('valide_le')->nullable();
            $table->timestamps();

            $table->index(['rendez_vous_id', 'statut']);
        });

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

        Schema::create('facturations_transcription', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->date('mois');
            $table->unsignedInteger('secondes');
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('montant_centimes');
            $table->string('stripe_ligne_id')->nullable();
            $table->string('stripe_facture_id')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'mois']);
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->unsignedInteger('plafond_transcription')->default(20000)->after('quota_extra_stockage_go');
            $table->json('termes_transcription')->nullable()->after('plafond_transcription');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn(['plafond_transcription', 'termes_transcription']);
        });
        Schema::dropIfExists('facturations_transcription');
        Schema::dropIfExists('parties_enregistrement');
        Schema::dropIfExists('enregistrements_consultation');
    }
};
