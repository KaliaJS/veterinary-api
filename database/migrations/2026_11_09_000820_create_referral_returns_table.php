<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le retour au vétérinaire référent (09.11.2026) : depuis le dossier de
 * l'animal référé, le confrère envoie ses pièces signées (compte rendu,
 * rapport de bloc, lettre de sortie, analyses…) à la clinique qui a référé.
 * Comme l'envoi C, rien n'est copié : elles se lisent depuis le dossier de
 * l'animal chez la clinique qui a référé, telles qu'à l'envoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_invitation_id')->constrained('referral_invitations')->cascadeOnDelete();
            $table->foreignId('clinique_id')->comment('La clinique qui renvoie (celle du confrère)')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('patient_id')->comment('L’animal chez le confrère')->constrained()->cascadeOnDelete();
            $table->json('items')->comment('Les pièces renvoyées : [{type, id}]');
            $table->text('note')->nullable()->comment('Chiffré : un mot au vétérinaire référent');
            $table->string('sent_by_name')->nullable();
            $table->timestamp('opened_at')->nullable()->comment('Première lecture par la clinique qui a référé');
            $table->timestamp('revoked_at')->nullable()->comment('Coupé par le confrère : ses pièces ne se lisent plus');
            $table->string('revoked_by_name')->nullable();
            $table->timestamps();
            $table->index(['referral_invitation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_returns');
    }
};
