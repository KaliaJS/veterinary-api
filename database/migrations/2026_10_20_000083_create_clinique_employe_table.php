<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Affectations : les cliniques de l'organisation où un employé
     * travaille, toutes ou quelques-unes, choisies par l'administrateur
     * à l'embauche puis sur la fiche. Rien d'automatique : une clinique
     * ouverte plus tard n'a personne tant qu'on ne l'y affecte pas. On ne
     * voit et n'agit que dans les cliniques où l'on est affecté.
     */
    public function up(): void
    {
        Schema::create('clinique_employe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employe_id', 'clinique_id']);
            $table->index('clinique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinique_employe');
    }
};
