<?php

use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datetimes UTC (docs/data-model.md §3). Le chevauchement est une
     * décision produit : AUCUNE contrainte d'exclusivité — l'empilement
     * est résolu à l'affichage. `patient_name` / `owner_name` restent
     * des dénormalisations de confort (vides pour un tampon) ; les FK
     * `patient_id` / `client_id` font foi quand la fiche existe.
     */
    public function up(): void
    {
        Schema::create('rendez_vous', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('ligne_planning_id')->constrained('lignes_planning')->restrictOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('salle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('patient_name')->nullable();
            $table->enum('species', array_column(Espece::cases(), 'value'))->nullable();
            $table->string('owner_name')->nullable();
            $table->enum('type', array_column(TypeRendezVous::cases(), 'value'));
            $table->enum('status', array_column(StatutRendezVous::cases(), 'value'))->default('prevu');
            $table->json('flags')->nullable();
            $table->string('reason')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('arrived_at')->nullable()->comment('Renseignée au passage en statut « salle »');
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['clinique_id', 'ligne_planning_id', 'starts_at']);
            $table->index(['clinique_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
        });

        // La demande en ligne acceptée pointe son rendez-vous (table créée avant celle-ci)
        Schema::table('demandes_rendez_vous', function (Blueprint $table) {
            $table->foreign('rendez_vous_id')->references('id')->on('rendez_vous')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('demandes_rendez_vous', function (Blueprint $table) {
            $table->dropForeign(['rendez_vous_id']);
        });
        Schema::dropIfExists('rendez_vous');
    }
};
