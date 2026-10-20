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
            $table->foreignId('produit_id')->nullable()->constrained('produits_veterinaires')->nullOnDelete();
            $table->string('nom')->comment('Maladies couvertes (« Typhus / Coryza »…)');
            $table->json('valences')->nullable()->comment('Codes des valences au moment de l injection');
            $table->string('marque')->nullable();
            $table->string('lot')->nullable();
            $table->string('gtin', 14)->nullable();
            $table->date('peremption_le')->nullable();
            $table->string('voie', 40)->nullable();
            $table->string('site_injection', 60)->nullable();
            $table->text('remarque')->nullable();
            $table->text('reaction')->nullable()->comment('Effet indésirable observé');
            $table->foreignId('employe_id')->nullable()->constrained()->nullOnDelete();
            $table->date('injectee_le');
            $table->date('echeance_le')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'echeance_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccinations');
    }
};
