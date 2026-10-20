<?php

use App\Enums\StatutDemandeRattachement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demande de rattachement : un client réclame, par numéro de puce, un
     * animal déjà enregistré par une clinique. La clinique accepte (le
     * patient reçoit proprietaire_id) ou refuse.
     */
    public function up(): void
    {
        Schema::create('demandes_rattachement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('puce_saisie', 24);
            $table->enum('statut', array_column(StatutDemandeRattachement::cases(), 'value'))->default('attente');
            $table->timestamp('traitee_le')->nullable();
            $table->foreignId('traitee_par')->nullable()->constrained('employes')->nullOnDelete();
            $table->timestamps();

            $table->index(['organisation_id', 'statut']);
            $table->index(['user_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_rattachement');
    }
};
