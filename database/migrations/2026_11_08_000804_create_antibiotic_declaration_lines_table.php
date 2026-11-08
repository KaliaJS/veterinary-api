<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026) : une préparation d'une déclaration, avec ce que
 * l'annexe de l'O-SI ABV demande (ch. 2.1.5.2 et 2.2.3) : quantité par
 * administration, fréquence, jours de traitement, quantité totale
 * nécessaire, quantité remise ; un 2e dosage facultatif. Les quantités sont
 * dans l'unité de la préparation (liste de l'OSAV).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antibiotic_declaration_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('declaration_id')->constrained('antibiotic_declarations')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ordonnance_ligne_id')->nullable()->constrained()->nullOnDelete();
            $table->string('preparation_code', 12)->nullable();
            $table->string('route_code', 4)->nullable();
            $table->decimal('dose_1', 12, 4)->nullable()->comment('Quantité par administration (1er dosage)');
            $table->string('frequency_1', 8)->nullable()->comment('Fréquence (1er dosage), code de l’OSAV');
            $table->unsignedSmallInteger('days_1')->nullable();
            $table->decimal('dose_2', 12, 4)->nullable();
            $table->string('frequency_2', 8)->nullable();
            $table->unsignedSmallInteger('days_2')->nullable();
            $table->decimal('total_quantity', 14, 4)->nullable()->comment('Quantité totale nécessaire (consommation totale)');
            $table->decimal('given_quantity', 14, 4)->nullable()->comment('Quantité remise');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_critical_confirmed')->default(false)->comment('Substance critique prescrite exprès, confirmé par le vétérinaire');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antibiotic_declaration_lines');
    }
};
