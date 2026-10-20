<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rôles configurables PAR ORGANISATION : quatre rôles par défaut
     * créés à l'inscription (Vétérinaire, ASV, Assistant,
     * Administrateur), modifiables ensuite. `domaines` : liste JSON de
     * valeurs DomainePermission.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->json('domaines');
            $table->timestamps();

            $table->unique(['organisation_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
