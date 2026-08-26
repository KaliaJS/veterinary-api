<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dossier documentaire du patient : certificats, consentements
     * signés, comptes rendus PDF… (docs/data-model.md §13).
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('type')->nullable()->comment('certificat, consentement, compte-rendu…');
            $table->string('chemin')->nullable()->comment('Fichier stocké');
            $table->string('signataire')->nullable();
            $table->date('etabli_le');
            $table->timestamps();

            $table->index(['patient_id', 'etabli_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
