<?php

use App\Enums\Specialite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spécialités d'un vétérinaire, sur SON compte : il les déclare
     * lui-même (Réglages, Profil) et elles le suivent dans toutes ses
     * cliniques et organisations. Celles d'une clinique se déduisent de
     * ses vétérinaires affectés ; l'annuaire ne les montre qu'une fois le
     * vétérinaire vérifié.
     */
    public function up(): void
    {
        Schema::create('specialites_veterinaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('specialite', array_column(Specialite::cases(), 'value'));
            $table->timestamps();

            $table->unique(['user_id', 'specialite']);
            $table->index('specialite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialites_veterinaire');
    }
};
