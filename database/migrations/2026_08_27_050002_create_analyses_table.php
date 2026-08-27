<?php

use App\Enums\PrioriteAnalyse;
use App\Enums\StatutAnalyse;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demandes d'analyses du laboratoire (docs/data-model.md §9) — PAR
     * CLINIQUE : le prélèvement, les tubes et l'automate sont
     * physiques, la worklist de l'écran Laboratoire est celle de la
     * clinique. La fiche patient, elle, lit les analyses de toutes les
     * cliniques de l'organisation. Numéro A-YY-NNNN par séquence
     * annuelle d'organisation (Numeroteur). Jamais de suppression : le
     * cycle se clôt en `ok` (validé signé).
     */
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('numero', 12)->unique();
            $table->enum('statut', array_column(StatutAnalyse::cases(), 'value'))->default(StatutAnalyse::Attente->value);
            $table->enum('priorite', array_column(PrioriteAnalyse::cases(), 'value'))->default(PrioriteAnalyse::Standard->value);
            $table->dateTime('preleve_le');
            $table->foreignId('prescripteur_id')->constrained('employes')->restrictOnDelete();
            $table->foreignId('valide_par_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->dateTime('valide_le')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinique_id', 'statut']);
            $table->index(['clinique_id', 'preleve_le']);
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
