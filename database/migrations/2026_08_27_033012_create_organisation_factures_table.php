<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Factures d'abonnement et de packs de quota (docs/data-model.md
     * §2) — séquence « F- » propre, distincte des factures clientes
     * « FC- ». Émises par l'éditeur : jamais modifiées ni supprimées.
     */
    public function up(): void
    {
        Schema::create('organisation_factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('numero', 20)->unique();
            $table->date('emise_le');
            $table->string('libelle');
            $table->decimal('montant', 8, 2)->comment('TTC CHF — TVA 8.1 % incluse');
            $table->timestamps();

            $table->index(['organisation_id', 'emise_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_factures');
    }
};
