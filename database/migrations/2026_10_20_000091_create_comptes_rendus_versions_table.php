<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versions d'un compte rendu SOAP avant sa signature (23.10.2026) :
     * à chaque modification, le texte remplacé et qui l'avait écrit.
     */
    public function up(): void
    {
        Schema::create('comptes_rendus_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compte_rendu_id')->constrained('comptes_rendus')->cascadeOnDelete();
            $table->text('s')->nullable();
            $table->text('o')->nullable();
            $table->text('a')->nullable();
            $table->text('p')->nullable();
            $table->foreignId('user_id')->nullable()->comment('Rédacteur de cette version')->constrained()->nullOnDelete();
            $table->timestamp('redigee_le')->comment('Quand cette version avait été enregistrée');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes_rendus_versions');
    }
};
