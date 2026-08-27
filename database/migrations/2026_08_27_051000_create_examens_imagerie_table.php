<?php

use App\Enums\ModaliteImagerie;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Examens d'imagerie (docs/data-model.md §10) — PAR CLINIQUE : la
     * modalité est une machine physique, la worklist est celle de la
     * clinique ; la fiche patient lit les examens de toutes les
     * cliniques de l'organisation. L'examen est le dossier métier
     * maître — le RDV `imagerie` du planning, quand il est lié, n'est
     * que sa projection (même patron que le bloc). Le compte rendu
     * signé (signe_le) fige l'examen ; jamais de suppression.
     */
    public function up(): void
    {
        Schema::create('examens_imagerie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('rendez_vous_id')->nullable()->unique()->constrained('rendez_vous')->nullOnDelete();
            $table->enum('modalite', array_column(ModaliteImagerie::cases(), 'value'));
            $table->string('libelle');
            $table->string('incidence')->nullable()->comment('Incidence / sonde (« Profil droit · Face »)');
            $table->dateTime('realise_le');
            $table->unsignedSmallInteger('duree_minutes')->default(30);
            $table->foreignId('prescripteur_id')->constrained('employes')->restrictOnDelete();
            $table->text('constatations')->nullable();
            $table->text('conclusion')->nullable();
            $table->foreignId('signe_par_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->dateTime('signe_le')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinique_id', 'realise_le']);
            $table->index(['clinique_id', 'signe_le']);
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examens_imagerie');
    }
};
