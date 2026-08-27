<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sessions de téléconsultation (tele.js du front) — PAR CLINIQUE.
     * La session est le dossier maître de l'appel vidéo ; le RDV
     * `tele` du planning, quand il est lié, n'est que sa projection
     * (même patron que le bloc) : le démarrage le passe en `cours`, la
     * clôture en `fini` avec facture. Pas d'unicité sur le RDV : un
     * appel quitté peut être rappelé — nouvelle session, même RDV.
     * Jamais de suppression ; une session terminée est figée, sa durée
     * est servie par le serveur.
     */
    public function up(): void
    {
        Schema::create('sessions_teleconsultation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('rendez_vous_id')->nullable()->constrained('rendez_vous')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->dateTime('demarre_le');
            $table->foreignId('demarre_par_id')->constrained('employes')->restrictOnDelete();
            $table->dateTime('terminee_le')->nullable();
            $table->unsignedInteger('duree_secondes')->nullable()->comment('Servie par le serveur à la clôture');
            $table->boolean('cloture_rendez_vous')->default(false)->comment('La fin d\'appel a clos le RDV lié (fini + facture)');
            $table->timestamps();

            $table->index(['clinique_id', 'demarre_le']);
            $table->index('rendez_vous_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions_teleconsultation');
    }
};
