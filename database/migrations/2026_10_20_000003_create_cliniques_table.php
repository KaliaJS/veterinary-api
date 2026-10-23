<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliniques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained()->restrictOnDelete();
            $table->string('nom');
            $table->string('raison_sociale')->nullable();
            $table->string('rue')->nullable();
            $table->string('npa', 4)->nullable();
            $table->string('ville')->nullable();
            $table->decimal('latitude', 8, 5)->nullable();
            $table->decimal('longitude', 8, 5)->nullable();
            $table->string('tel')->nullable();
            $table->string('email')->nullable();
            $table->string('fuseau_horaire')->default('Europe/Zurich');
            $table->unsignedTinyInteger('nombre_boxes')->default(0)->comment('Capacité d’hospitalisation');
            $table->boolean('affiche_veterinaires')->default(true)->comment('Annuaire public : liste des vétérinaires et de leurs spécialités, sinon les icônes seules');
            $table->boolean('rdv_sans_compte')->default(false)->comment('Demandes de rendez-vous en ligne sans compte Veterinary (nom, e-mail et téléphone)');
            $table->boolean('rdv_a_valider')->default(false)->comment('Chaque rendez-vous pris en ligne attend la réponse de la clinique ; sinon l’heure libre est réservée aussitôt');
            $table->boolean('urgences_a_valider')->default(true)->comment('Une urgence prise en ligne attend la réponse de la clinique');
            $table->unsignedInteger('acompte_centimes')->nullable()->comment('Acompte payé en ligne pour réserver (contre les rendez-vous non honorés), déduit de la facture, jamais rendu d’office ; null : aucun');
            $table->boolean('paiements_integres')->default(true)->comment('Cartes et TWINT encaissés par Veterinary (compte de paiement de la société) ; faux : la clinique garde son terminal (Worldline…) et l’équipe marque les paiements comme payés');
            $table->string('photo_chemin')->nullable()->comment('Photo de la clinique (annuaire), posée par l’administrateur');
            $table->text('photo_apercu')->nullable()->comment('Aperçu flou de la photo, quelques centaines d’octets en data URI');
            $table->string('photo_fichier', 64)->nullable()->index()->comment('Nom du fichier de la photo, sans dossier ni extension : la route publique la retrouve par lui (23.10.2026)');
            $table->string('stripe_location_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliniques');
    }
};
