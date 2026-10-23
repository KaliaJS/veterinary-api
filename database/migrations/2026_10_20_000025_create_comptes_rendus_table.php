<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compte rendu SOAP — un par rendez-vous (docs/data-model.md §13).
     * Les quatre champs gardent les codes du front : `s` motif &
     * anamnèse, `o` examen clinique, `a` diagnostic, `p` traitement &
     * plan. `user_id` : dernier rédacteur. Signé (23.10.2026 :
     * `signe_le`, `signe_par_id`, l'employé du jeton), il ne change plus :
     * des compléments s'y ajoutent ; avant, chaque modification garde la
     * version précédente (`comptes_rendus_versions`).
     */
    public function up(): void
    {
        Schema::create('comptes_rendus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rendez_vous_id')->constrained('rendez_vous')->cascadeOnDelete();
            $table->text('s')->nullable();
            $table->text('o')->nullable();
            $table->text('a')->nullable();
            $table->text('p')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('signe_le')->nullable()->comment('Signé : le texte ne change plus, des compléments s’ajoutent (23.10.2026)');
            $table->foreignId('signe_par_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->timestamps();

            $table->unique('rendez_vous_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes_rendus');
    }
};
