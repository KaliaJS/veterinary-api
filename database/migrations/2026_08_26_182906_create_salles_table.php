<?php

use App\Enums\TypeSalle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table unique pour les trois notions de salle du front :
     * consultation (champ `room` d'un RDV), bloc opératoire et imagerie
     * (salles-ressources pouvant porter une ligne du planning).
     */
    public function up(): void
    {
        Schema::create('salles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->enum('type', array_column(TypeSalle::cases(), 'value'));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salles');
    }
};
