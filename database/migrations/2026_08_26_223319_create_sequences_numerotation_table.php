<?php

use App\Enums\PieceNumerotee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Séquences annuelles par organisation et par type de pièce
     * (docs/data-model.md §18) — jamais de `length + offset` comme la
     * démo. La ligne est verrouillée (lockForUpdate) à l'attribution.
     */
    public function up(): void
    {
        Schema::create('sequences_numerotation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->enum('piece', array_column(PieceNumerotee::cases(), 'value'));
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('prochain')->default(1);
            $table->timestamps();

            $table->unique(['organisation_id', 'piece', 'annee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences_numerotation');
    }
};
