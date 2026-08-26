<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table unique d'équipe — réconcilie `planning.staff` et
     * `org.clinics[].team` du front (divergence signalée dans
     * docs/data-model.md). `user_id` relie l'employé à son compte de
     * connexion (acteur des audits de RDV).
     */
    public function up(): void
    {
        Schema::create('employes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nom');
            $table->string('initiales', 3);
            $table->string('role')->comment('« Vétérinaire », « ASV », « ASV · Accueil »…');
            $table->unsignedTinyInteger('taux')->default(100)->comment('% d’activité');
            $table->string('contrat')->nullable()->comment('« CDI », « CDD → 12.2026 »…');
            $table->date('entree_le')->nullable();
            $table->string('tel')->nullable();
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('conges_droit')->default(25);
            $table->unsignedTinyInteger('conges_pris')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employes');
    }
};
