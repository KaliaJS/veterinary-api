<?php

use App\Enums\ModePrescription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prescription jointe à la facture (`rx` du front), imprimable
     * depuis l'encaissement. Quand un registre existe, l'ordonnance est
     * l'entité de référence (factures.ordonnance_id) — ces lignes en
     * sont le rendu, et portent seules le mode « pharmacie » (aucune
     * ligne facturée, aucun stock).
     */
    public function up(): void
    {
        Schema::create('facture_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('posologie')->default('Posologie à préciser');
            $table->enum('mode', array_column(ModePrescription::cases(), 'value'));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facture_prescriptions');
    }
};
