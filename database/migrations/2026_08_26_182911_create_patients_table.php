<?php

use App\Enums\Espece;
use App\Enums\Sexe;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les alertes de fiche sont des colonnes dédiées : `est_agressif`,
     * `a_allergies` ; l'alerte « solde » est dérivée de `dette`.
     * `ne_le` remplace l'âge en années du front (dérivable).
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('nom');
            $table->enum('espece', array_column(Espece::cases(), 'value'));
            $table->string('race')->nullable();
            $table->enum('sexe', array_column(Sexe::cases(), 'value'));
            $table->date('ne_le')->nullable();
            $table->boolean('est_sterilise')->default(false);
            $table->string('puce', 24)->nullable()->comment('Format AMICUS « 756 09X XXX XXX XXX »');
            $table->decimal('dette', 8, 2)->default(0)->comment('Solde dû en CHF — alerte « solde » dérivée');
            $table->boolean('est_agressif')->default(false);
            $table->boolean('a_allergies')->default(false);
            $table->text('traitement_en_cours')->nullable();
            $table->boolean('rappels_coupes')->default(false);
            $table->dateTime('amicus_declare_le')->nullable()->comment('Chiens — déclaration AMICUS (exige une puce)');
            $table->year('client_depuis')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'nom']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
