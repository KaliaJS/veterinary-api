<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rappels par e-mail (décision de Jim, 28.10.2026) : réglages de la
 * clinique, refus du propriétaire (lien de l'e-mail), journal des envois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliniques', function (Blueprint $table): void {
            $table->boolean('rappels_email')->default(true);
            $table->boolean('rappels_sms')->default(false);
            $table->unsignedTinyInteger('rappels_delai_jours')->default(7);
            $table->boolean('rappels_vaccins')->default(true);
            $table->boolean('rappels_controles')->default(true);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('rappels_email')->default(true);
        });

        Schema::create('rappels_envoyes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('objet', 20)->comment('vaccin | controle');
            $table->foreignId('vaccination_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('rendez_vous_id')->nullable()->constrained('rendez_vous')->cascadeOnDelete();
            $table->string('libelle');
            $table->date('echeance_le');
            $table->string('canal', 10);
            $table->string('adresse')->nullable();
            $table->string('statut', 10)->comment('envoye | echec');
            $table->text('erreur')->nullable();
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->boolean('manuel')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('envoye_le')->nullable();
            // Un rappel automatique par échéance et par canal, jamais deux
            $table->string('cle', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['clinique_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rappels_envoyes');
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('rappels_email');
        });
        Schema::table('cliniques', function (Blueprint $table): void {
            $table->dropColumn(['rappels_email', 'rappels_sms', 'rappels_delai_jours', 'rappels_vaccins', 'rappels_controles']);
        });
    }
};
