<?php

use App\Enums\EtatDent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Odontogramme Triadan : une ligne par dent annotée, l'absence de
     * ligne vaut « sain » (docs/data-model.md §4).
     */
    public function up(): void
    {
        Schema::create('releves_dentaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('dent')->comment('N° Triadan');
            $table->enum('etat', array_column(EtatDent::cases(), 'value'));
            $table->timestamps();

            $table->unique(['patient_id', 'dent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releves_dentaires');
    }
};
