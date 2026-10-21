<?php

use App\Enums\Espece;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Animaux soignés par une clinique, cochés par l'administrateur :
     * les espèces des dossiers patients (même liste, mêmes codes), pour
     * l'annuaire public (fiche, filtre).
     */
    public function up(): void
    {
        Schema::create('clinique_especes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->enum('espece', array_column(Espece::cases(), 'value'));
            $table->timestamps();

            $table->unique(['clinique_id', 'espece']);
            $table->index('espece');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinique_especes');
    }
};
