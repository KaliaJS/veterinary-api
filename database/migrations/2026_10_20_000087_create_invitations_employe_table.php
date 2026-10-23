<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations à rejoindre une organisation, envoyées par e-mail par
     * l'administrateur (l'autre chemin que le code employé). Rien n'est
     * créé avant l'acceptation : la personne se connecte avec l'adresse
     * invitée (son compte naît alors s'il n'existe pas), accepte, et sa
     * fiche est créée avec ce que l'administrateur avait saisi
     * (`donnees` : poste, rôle, contrat, cliniques…). Le lien porte un
     * jeton dont seule l'empreinte est gardée.
     */
    public function up(): void
    {
        Schema::create('invitations_employe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('email')->comment('Adresse invitée, en minuscules : seul un compte à cette adresse peut accepter');
            $table->string('nom');
            $table->json('donnees')->comment('La fiche à créer : poste, rôle, contrat, cliniques…');
            $table->char('jeton_empreinte', 64)->unique()->comment('SHA-256 du jeton du lien');
            $table->foreignId('invitee_par')->nullable()->constrained('employes')->nullOnDelete();
            $table->timestamp('expire_le');
            $table->timestamp('acceptee_le')->nullable();
            $table->foreignId('employe_id')->nullable()->comment('La fiche créée à l’acceptation')->constrained()->nullOnDelete();
            $table->timestamp('annulee_le')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations_employe');
    }
};
