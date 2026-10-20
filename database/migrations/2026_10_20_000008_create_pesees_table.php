<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->decimal('poids_kg', 6, 3);
            $table->date('pesee_le');
            $table->timestamps();

            $table->index(['patient_id', 'pesee_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesees');
    }
};
