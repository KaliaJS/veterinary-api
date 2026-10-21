<?php

use App\Enums\TypeParcours;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parcours d'un vétérinaire, sur SON compte (Réglages, Profil) : ses
     * postes hors Veterinary et ses formations, avec leurs années. Ses
     * postes dans les cliniques Veterinary s'ajoutent d'eux-mêmes sur sa
     * fiche publique (fiches employé).
     */
    public function up(): void
    {
        Schema::create('parcours_veterinaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', array_column(TypeParcours::cases(), 'value'));
            $table->string('titre', 160)->comment('« Vétérinaire assistant », « Doctorat en médecine vétérinaire »');
            $table->string('lieu', 160)->nullable()->comment('Clinique, université, école');
            $table->string('ville', 80)->nullable();
            $table->unsignedSmallInteger('annee_debut')->nullable();
            $table->unsignedSmallInteger('annee_fin')->nullable()->comment('Poste : vide s’il est en cours ; formation : année du diplôme');
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'type', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcours_veterinaire');
    }
};
