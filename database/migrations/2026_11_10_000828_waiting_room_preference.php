<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La salle d'attente dans « Rendez-vous » (10.11.2026, demande de Jim) :
 * chaque personne l'allume ou l'éteint dans Réglages, Planning ; éteinte
 * par défaut, toutes les cliniques n'ont pas de salle d'attente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_preferences', function (Blueprint $table) {
            $table->boolean('waiting_room')->default(false)->comment('Colonne de la salle d’attente à gauche du planning');
        });
    }

    public function down(): void
    {
        Schema::table('staff_preferences', fn (Blueprint $table) => $table->dropColumn('waiting_room'));
    }
};
