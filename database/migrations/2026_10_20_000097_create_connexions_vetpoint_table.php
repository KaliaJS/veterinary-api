<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vetpoint (27.10.2026) : la clé d'API de chaque clinique, générée dans
     * son profil Vetpoint. Chiffrée (cast `encrypted`), jamais renvoyée ;
     * une clinique, une clé : chaque site a son compte, ses prix, ses adresses.
     */
    public function up(): void
    {
        Schema::create('connexions_vetpoint', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('cle');
            $table->timestamp('verifiee_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connexions_vetpoint');
    }
};
