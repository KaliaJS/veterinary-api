<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checklist de sécurité d'une intervention : les six points de base
     * (`jeun`, `consent`, `premed`, `iv`, `atb`, `mat`) créés avec
     * l'intervention, plus les points ajoutés à la volée
     * (`est_ajoute`) — seuls ces derniers se retirent. Le passage en
     * `cours` (incision) est bloqué tant que tout n'est pas coché ;
     * `consent` ne se coche que par la signature du consentement.
     */
    public function up(): void
    {
        Schema::create('intervention_checklist_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('libelle');
            $table->boolean('est_coche')->default(false);
            $table->boolean('est_ajoute')->default(false);
            $table->timestamps();

            $table->unique(['intervention_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_checklist_points');
    }
};
