<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lettre de référence envoyée dans Veterinary, envoi C (choix de Jim,
 * 09.11.2026) : une invitation à un vétérinaire (choisi dans l'annuaire ou
 * par son e-mail) ou à toute une clinique. Rien n'est copié : accepté,
 * l'animal choisi ou créé chez le destinataire devient un de ses patients
 * « référés », et la lettre et ses pièces se consultent depuis son dossier,
 * jusqu'à ce que la clinique qui réfère coupe l'accès.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_letter_id')->constrained('referral_letters')->cascadeOnDelete();
            // Qui réfère
            $table->foreignId('organisation_id')->comment('Organisation qui réfère')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->comment('Clinique qui réfère')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('patient_id')->comment('L’animal, chez la clinique qui réfère')->constrained()->cascadeOnDelete();
            $table->string('sent_by_name')->nullable();
            // À qui : un vétérinaire (son compte) ou toute une clinique
            $table->string('recipient_kind', 8)->comment('vet, clinic');
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_clinique_id')->nullable()->constrained('cliniques')->cascadeOnDelete();
            $table->string('recipient_name')->nullable()->comment('Nom affiché du destinataire, au moment de l’envoi');
            $table->string('status', 10)->default('pending')->comment('pending, accepted, refused, revoked');
            $table->timestamp('opened_at')->nullable()->comment('Première lecture de l’invitation');
            // Acceptée : l'animal chez le destinataire
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accepted_by_name')->nullable();
            $table->foreignId('target_clinique_id')->nullable()->constrained('cliniques')->nullOnDelete();
            $table->foreignId('target_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->timestamp('refused_at')->nullable();
            $table->string('refused_by_name')->nullable();
            $table->string('refusal_reason', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->timestamps();
            $table->index(['recipient_user_id', 'status']);
            $table->index(['recipient_clinique_id', 'status']);
            $table->index(['target_patient_id', 'status']);
            $table->index(['referral_letter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_invitations');
    }
};
