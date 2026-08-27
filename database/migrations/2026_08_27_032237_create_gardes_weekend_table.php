<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Échanges de la rotation des gardes de week-end
     * (docs/data-model.md §1) : la rotation elle-même est DÉRIVÉE
     * (numéro de semaine modulo l'effectif vétérinaire) — seule une
     * réattribution est une donnée, un samedi → un employé.
     */
    public function up(): void
    {
        Schema::create('gardes_weekend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->date('samedi_le');
            $table->foreignId('employe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['clinique_id', 'samedi_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gardes_weekend');
    }
};
