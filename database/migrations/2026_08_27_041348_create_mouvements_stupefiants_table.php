<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre des stupéfiants (art. 62 OCStup, docs/data-model.md §16) :
     * journal immuable tenu PAR CLINIQUE — jamais d'update ni de delete,
     * une erreur se corrige par un mouvement inverse. `quantite` est
     * signée dans l'unité du produit (+ réception / − administration) ;
     * le patient est obligatoire pour une sortie, absent pour une
     * réception ; le signataire est l'employé du jeton. Clés en
     * restrict : un registre légal ne se vide pas par cascade.
     */
    public function up(): void
    {
        Schema::create('mouvements_stupefiants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('produit_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employe_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantite', 8, 2)->comment('Signée : + réception, − sortie');
            $table->string('motif');
            $table->dateTime('created_at');

            $table->index(['clinique_id', 'produit_id', 'created_at']);
            $table->index(['clinique_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stupefiants');
    }
};
