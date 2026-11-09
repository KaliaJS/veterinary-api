<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lettre de référence vers un confrère (choix de Jim, 09.11.2026) :
 * rédigée par l'IA depuis les pièces jointes du dossier et les notes du
 * vétérinaire (motif, question), tapées ou dictées ; relue, signée, figée,
 * rangée dans les documents de l'animal. Envoyée au choix : PDF, lien
 * provisoire (un partage du dossier), ou dans Veterinary (invitation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_letters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('empty')->comment('empty, writing, draft, failed, signed');
            $table->string('error', 32)->nullable()->comment('writings, budget, writer');
            $table->json('items')->comment('Les pièces jointes : [{type, id}]');
            $table->boolean('include_visit_notes')->default(false);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_clinic')->nullable();
            $table->string('recipient_email')->nullable();
            $table->text('notes')->nullable()->comment('Chiffré : motif et question, en mots-clés');
            $table->text('draft')->nullable()->comment('Chiffré : sources, phrases et leurs sources, doutes');
            $table->text('sections')->nullable()->comment('Chiffré : le texte relu, par rubrique');
            $table->json('edited_sections')->nullable();
            $table->json('edited_at')->nullable();
            $table->text('copied')->nullable()->comment('Chiffré : identité et traitement en cours, figés à la signature');
            $table->unsignedTinyInteger('writings')->default(0);
            $table->boolean('is_redraft_pending')->default(false);
            $table->timestamp('written_at')->nullable();
            $table->timestamp('sources_changed_at')->nullable()->comment('Dernier changement des pièces, des notes ou des notes de visites');
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->string('signed_by_name')->nullable();
            $table->foreignId('document_id')->nullable()->comment('La lettre signée, rangée au dossier')->constrained('documents')->nullOnDelete();
            $table->foreignId('record_share_id')->nullable()->comment('Le lien provisoire (envoi B)')->constrained('record_shares')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_id', 'status']);
        });

        Schema::table('enregistrements_consultation', function (Blueprint $table): void {
            $table->foreignId('referral_letter_id')->nullable()->after('sejour_id')->comment('Dictée du motif et de la question d’une lettre de référence')->constrained('referral_letters')->cascadeOnDelete();
            $table->index(['referral_letter_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::table('enregistrements_consultation', function (Blueprint $table): void {
            // La clé étrangère d'abord : MySQL garde l'index tant qu'elle s'en sert
            $table->dropForeign(['referral_letter_id']);
            $table->dropIndex(['referral_letter_id', 'statut']);
            $table->dropColumn('referral_letter_id');
        });
        Schema::dropIfExists('referral_letters');
    }
};
