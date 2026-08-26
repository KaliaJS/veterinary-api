<?php

use App\Enums\ResultatFluoresceine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releves_ophtalmologiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->date('releve_le');
            $table->unsignedTinyInteger('schirmer_od')->nullable()->comment('mm/min');
            $table->unsignedTinyInteger('schirmer_og')->nullable();
            $table->unsignedTinyInteger('pio_od')->nullable()->comment('mmHg');
            $table->unsignedTinyInteger('pio_og')->nullable();
            $table->enum('fluoresceine', array_column(ResultatFluoresceine::cases(), 'value'))->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'releve_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releves_ophtalmologiques');
    }
};
