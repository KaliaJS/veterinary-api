<?php

use App\Enums\StatutSejour;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Séjours d'hospitalisation (docs/data-model.md §8) — PAR CLINIQUE
     * (le box est un lit physique, capacité `cliniques.nombre_boxes`).
     * Jamais de suppression : la sortie renseigne `sorti_le` et le
     * séjour reste au dossier, comme les rendez-vous annulés. La
     * facture du séjour naît à la sortie ; `sortie_prevue` reste un
     * texte libre (« Demain matin », « À réévaluer »), parité front.
     */
    public function up(): void
    {
        Schema::create('sejours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('box');
            $table->string('motif');
            $table->enum('statut', array_column(StatutSejour::cases(), 'value'))->default('stable');
            $table->foreignId('veterinaire_id')->nullable()->constrained('employes')->restrictOnDelete();
            $table->dateTime('admis_le');
            $table->string('sortie_prevue')->nullable();
            $table->dateTime('sorti_le')->nullable();
            $table->string('perfusion')->nullable()->comment('Perfusion en cours — « Ringer lactate · 45 ml/h »');
            $table->text('note')->nullable();
            $table->foreignId('facture_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinique_id', 'sorti_le', 'box']);
            $table->index(['patient_id', 'admis_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sejours');
    }
};
