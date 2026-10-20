<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demande de renouvellement d'une ordonnance depuis l'espace client
     * (« Renouveler en 1 clic » du proto) : en attente tant que la clinique
     * ne l'a pas traitée (renouvellement réel = POST /ordonnances/{id}/renouveler,
     * geste vétérinaire, qui pose `traitee_le`).
     */
    public function up(): void
    {
        Schema::create('demandes_renouvellement_ordonnance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordonnance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('traitee_le')->nullable();
            $table->timestamps();

            $table->index(['ordonnance_id', 'traitee_le'], 'demandes_renouv_ordonnance_attente_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_renouvellement_ordonnance');
    }
};
