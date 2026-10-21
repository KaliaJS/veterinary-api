<?php

use App\Enums\ModaliteUrgences;
use App\Enums\ServiceClinique;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Services d'une clinique, cochés par l'administrateur (écran
     * Organisation) : une ligne par service proposé. Ils ouvrent les
     * modules de l'espace vétérinaire (middleware `service:`) et
     * s'affichent dans l'annuaire public. Une clinique créée n'en a
     * aucun. Les urgences précisent leur modalité (24 h sur 24 ou aux
     * heures d'ouverture) ; la capacité d'hospitalisation reste
     * `cliniques.nombre_boxes`.
     */
    public function up(): void
    {
        Schema::create('clinique_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->enum('service', array_column(ServiceClinique::cases(), 'value'));
            $table->enum('modalite', array_column(ModaliteUrgences::cases(), 'value'))->nullable()->comment('Urgences seulement');
            $table->timestamps();

            $table->unique(['clinique_id', 'service']);
            $table->index('service');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinique_services');
    }
};
