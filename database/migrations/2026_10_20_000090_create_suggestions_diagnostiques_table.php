<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suggestion diagnostique (23.10.2026) : à la demande du vétérinaire,
     * Claude lit le compte rendu SOAP en cours, la transcription s'il y en a
     * une et le signalement de l'animal, et propose un diagnostic
     * différentiel raisonné (hypothèses, examens, signaux d'alerte). Une
     * aide à la réflexion, jamais un diagnostic. Facturée à la suggestion
     * rendue, ajoutée au renouvellement de l'abonnement, avec un plafond
     * par période. Le compte rendu envoyé et la suggestion sont chiffrés.
     */
    public function up(): void
    {
        Schema::create('suggestions_diagnostiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rendez_vous_id')->constrained('rendez_vous')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('statut', 10)->default('en_cours')->comment('en_cours | prete | erreur');
            $table->text('soap')->nullable()->comment('Compte rendu tel qu’envoyé (chiffré)');
            $table->boolean('avec_transcription')->default(false);
            $table->longText('contenu')->nullable()->comment('Suggestion rendue (chiffrée)');
            $table->string('modele', 40)->nullable();
            $table->unsignedInteger('jetons_entree')->nullable();
            $table->unsignedInteger('jetons_sortie')->nullable();
            $table->unsignedInteger('montant_centimes')->default(0)->comment('Facturé, sans TVA ; 0 tant qu’elle n’est pas rendue');
            $table->foreignId('organisation_facture_id')->nullable()->comment('Facture de la plateforme qui la porte ; nulle : à facturer')->constrained()->nullOnDelete();
            $table->timestamp('rendue_le')->nullable();
            $table->timestamps();

            $table->index(['rendez_vous_id', 'id']);
            $table->index(['organisation_id', 'statut', 'rendue_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions_diagnostiques');
    }
};
