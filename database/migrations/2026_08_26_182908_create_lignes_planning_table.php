<?php

use App\Enums\RoleLignePlanning;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une ligne du planning est soit un praticien (`employe_id`), soit
     * une ressource (`salle_id` : bloc, imagerie) — jamais les deux
     * (contrainte applicative, docs/data-model.md).
     */
    public function up(): void
    {
        Schema::create('lignes_planning', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('employe_id')->nullable()->constrained('employes')->restrictOnDelete();
            $table->foreignId('salle_id')->nullable()->constrained('salles')->restrictOnDelete();
            $table->string('nom');
            $table->string('sous_titre')->nullable()->comment('« CONSULTATION », « CONSULT · URGENCES »…');
            $table->enum('role', array_column(RoleLignePlanning::cases(), 'value'));
            $table->string('initiales', 3)->nullable();
            $table->string('groupe')->comment('Groupe repliable : Consultation, Chirurgie, Imagerie');
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_planning');
    }
};
