<?php

use App\Enums\StatutIntervention;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interventions du bloc opératoire (docs/data-model.md §11) — PAR
     * CLINIQUE, dans une salle typée `bloc`. L'intervention est le
     * dossier métier maître ; le rendez-vous `chir` du planning en est
     * la projection, lié par `rendez_vous_id` (divergence « RDV vs
     * modèle parallèle » tranchée — docs/branchement.md §1.8).
     * `salle_nom` est dénormalisé comme l'audit des RDV : la salle
     * d'une intervention terminée survit à la suppression de la salle
     * (`nullOnDelete`), qui n'est refusée que sur l'en-cours.
     */
    public function up(): void
    {
        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('salle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('salle_nom')->comment('Dénormalisé — survit à la suppression de la salle');
            $table->foreignId('chirurgien_id')->constrained('employes')->restrictOnDelete();
            $table->foreignId('rendez_vous_id')->nullable()->constrained('rendez_vous')->nullOnDelete();
            $table->string('procedure');
            $table->dateTime('prevu_le');
            $table->unsignedSmallInteger('duree_minutes');
            $table->enum('statut', array_column(StatutIntervention::cases(), 'value'))->default('prevu');
            $table->json('protocole_anesthesique')->comment('Lignes libres : prémed, induction, maintien, analgésie');
            $table->text('note_operatoire')->nullable();
            $table->dateTime('incision_a')->nullable()->comment('Heure réelle de l\'incision (chrono du front)');
            $table->dateTime('consentement_signe_le')->nullable();
            $table->foreignId('consentement_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinique_id', 'prevu_le']);
            $table->unique('rendez_vous_id');
            $table->index(['salle_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventions');
    }
};
