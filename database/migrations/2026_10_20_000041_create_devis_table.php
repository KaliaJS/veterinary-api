<?php

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devis (docs/data-model.md §7) — NIVEAU ORGANISATION, patient
     * obligatoire (la FK fait foi, les dénormalisations d'affichage en
     * dérivent). `expire` n'est jamais stocké : il se dérive d'un
     * envoyé hors validité. L'acompte et la facture finale pointent les
     * factures créées en caisse.
     */
    public function up(): void
    {
        Schema::create('devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinique_id')->comment('Clinique qui établit le devis (en-tête des pièces)')->constrained()->restrictOnDelete();
            $table->string('numero', 20);
            $table->date('emis_le');
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('motif');
            $table->unsignedTinyInteger('remise_pct')->default(0);
            $table->unsignedTinyInteger('alea_pct')->default(0)->comment('Aléa affiché (fourchette haute) — informatif');
            $table->unsignedSmallInteger('validite_jours')->default(30);
            $table->date('valide_jusquau')->nullable()->comment('Posée à l’envoi');
            $table->unsignedTinyInteger('acompte_pct')->default(0);
            $table->text('conditions')->nullable();
            $table->enum('statut', array_column(StatutDevis::cases(), 'value'))->default('brouillon');
            $table->enum('canal', array_column(CanalDevis::cases(), 'value'))->nullable();
            $table->dateTime('signe_le')->nullable();
            $table->string('signe_par')->nullable();
            $table->foreignId('acompte_facture_id')->nullable()->constrained('factures')->nullOnDelete();
            $table->foreignId('facture_finale_id')->nullable()->constrained('factures')->nullOnDelete();
            $table->timestamps();

            // Numéro unique dans l'organisation : chaque organisation
            // numérote à partir de 1 (SequenceNumerotation)
            $table->unique(['organisation_id', 'numero']);
            $table->index(['organisation_id', 'statut']);
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devis');
    }
};
