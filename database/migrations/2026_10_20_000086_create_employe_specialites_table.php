<?php

use App\Enums\Specialite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spécialités d'un vétérinaire, sur sa fiche de l'organisation
     * (pas sur son compte : une autre organisation ne les voit ni ne
     * les modifie). Celles d'une clinique se déduisent de ses
     * vétérinaires affectés : rien à saisir pour la clinique.
     */
    public function up(): void
    {
        Schema::create('employe_specialites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employe_id')->constrained()->cascadeOnDelete();
            $table->enum('specialite', array_column(Specialite::cases(), 'value'));
            $table->timestamps();

            $table->unique(['employe_id', 'specialite']);
            $table->index('specialite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employe_specialites');
    }
};
