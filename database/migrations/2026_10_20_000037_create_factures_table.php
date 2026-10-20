<?php

use App\Enums\Espece;
use App\Enums\MoyenPaiement;
use App\Enums\StatutFacture;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La facture suit le patient — NIVEAU ORGANISATION — mais garde la
     * clinique d'émission (champ `clin` du front : CA par clinique,
     * clôture de caisse). Une seule facture par rendez-vous (la
     * réouverture retrouve la facture, jamais de doublon). Les
     * dénormalisations patient restent possibles sans fiche (RDV de
     * passage) ; la FK fait foi quand elle existe. `cree_par_id` sert
     * la fenêtre d'annulation d'une création.
     */
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->string('numero', 20);
            $table->dateTime('emise_le');
            $table->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('patient_name')->nullable();
            $table->enum('species', array_column(Espece::cases(), 'value'))->nullable();
            $table->string('owner_name')->nullable();
            $table->foreignId('ligne_planning_id')->nullable()->constrained('lignes_planning')->nullOnDelete();
            $table->foreignId('rendez_vous_id')->nullable()->constrained('rendez_vous')->restrictOnDelete();
            $table->foreignId('ordonnance_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('statut', array_column(StatutFacture::cases(), 'value'))->default('due');
            $table->enum('moyen_paiement', array_column(MoyenPaiement::cases(), 'value'))->nullable();
            $table->dateTime('payee_le')->nullable();
            $table->boolean('stock_sorti')->default(false)->comment('Sortie de stock des lignes med déjà effectuée — évite le double décrément');
            $table->foreignId('cloture_caisse_id')->nullable()->constrained('clotures_caisse')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinique_id', 'payee_le']);
            $table->index(['clinique_id', 'statut']);
            $table->unique('numero');
            $table->index(['organisation_id', 'statut']);
            $table->index('patient_id');
            $table->unique('rendez_vous_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
    }
};
