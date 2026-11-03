<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inviter un propriétaire dans son espace (03.11.2026, après vérification) :
 * rien n'est rattaché à la création. L'invitation attend sur la fiche :
 * l'adresse invitée, la date d'envoi, la clinique qui invite et le jeton du
 * lien, haché. La personne la prend connectée avec cette adresse (code
 * e-mail), dans les 30 jours ; le jeton est alors effacé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('space_invitation_email')->nullable()->comment('Adresse invitée dans l’espace, en minuscules');
            $table->timestamp('space_invitation_sent_at')->nullable()->comment('Envoi de l’invitation ; valable 30 jours');
            $table->char('space_invitation_token_hash', 64)->nullable()->unique()->comment('SHA-256 du jeton du lien, effacé une fois pris');
            $table->foreignId('space_invitation_clinic_id')->nullable()->comment('La clinique qui invite')->constrained('cliniques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('space_invitation_clinic_id');
            $table->dropUnique(['space_invitation_token_hash']);
            $table->dropColumn(['space_invitation_email', 'space_invitation_sent_at', 'space_invitation_token_hash']);
        });
    }
};
