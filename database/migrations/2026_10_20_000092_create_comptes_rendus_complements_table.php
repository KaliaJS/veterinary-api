<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compléments d'un compte rendu SOAP signé (23.10.2026) : le texte
     * signé ne change plus, ce qui vient après s'y ajoute, daté et signé
     * par son auteur, sans jamais se modifier.
     */
    public function up(): void
    {
        Schema::create('comptes_rendus_complements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compte_rendu_id')->constrained('comptes_rendus')->cascadeOnDelete();
            $table->text('texte');
            $table->foreignId('signe_par_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes_rendus_complements');
    }
};
