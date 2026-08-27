<?php

use App\Enums\DrapeauResultatLabo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Résultats d'une analyse — une ligne par paramètre de chaque
     * panel demandé, créées dès la demande avec bornes instantanées du
     * référentiel, valeurs saisies pendant l'analyse. Le drapeau H/L
     * est dérivé de la valeur et des bornes, jamais saisi. Immuables
     * après validation.
     */
    public function up(): void
    {
        Schema::create('analyse_resultats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analyse_id')->constrained()->restrictOnDelete();
            $table->foreignId('analyse_panel_id')->constrained('analyse_panels')->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('parametre');
            $table->string('unite', 20)->nullable();
            $table->decimal('borne_basse', 8, 2);
            $table->decimal('borne_haute', 8, 2);
            $table->decimal('valeur', 8, 2)->nullable();
            $table->enum('drapeau', array_column(DrapeauResultatLabo::cases(), 'value'))->nullable();
            $table->timestamps();

            $table->index('analyse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyse_resultats');
    }
};
