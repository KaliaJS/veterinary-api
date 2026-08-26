<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `injectee_le` / `echeance_le` remplacent les jours relatifs
     * `ago` / `due` du front. Une injection du jour écrase la ligne du
     * même vaccin (règle du carnet, appliquée côté application).
     */
    public function up(): void
    {
        Schema::create('vaccinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('nom')->comment('Maladies couvertes (« Typhus / Coryza »…)');
            $table->string('marque')->nullable();
            $table->string('lot')->nullable();
            $table->foreignId('employe_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->date('injectee_le');
            $table->date('echeance_le');
            $table->timestamps();

            $table->index(['patient_id', 'echeance_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccinations');
    }
};
