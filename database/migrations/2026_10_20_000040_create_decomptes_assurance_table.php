<?php

use App\Enums\StatutDecompteAssurance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Décompte d'assurance d'une facture (0..1 — docs/data-model.md
     * §6) : assureur affecté, suivi de la demande à envoyer → envoyée →
     * remboursée, horodatée à chaque avancée.
     */
    public function up(): void
    {
        Schema::create('decomptes_assurance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_id')->constrained()->cascadeOnDelete();
            $table->string('assureur', 100);
            $table->enum('statut', array_column(StatutDecompteAssurance::cases(), 'value'))->default('todo');
            $table->dateTime('envoye_le')->nullable();
            $table->dateTime('rembourse_le')->nullable();
            $table->timestamps();

            $table->unique('facture_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decomptes_assurance');
    }
};
