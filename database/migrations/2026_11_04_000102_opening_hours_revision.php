<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le numéro de la dernière saisie d'horaires appliquée (cinquième passe,
     * 04.11.2026) : le moment de la saisie dans le navigateur, en
     * millisecondes. Un envoi plus ancien, arrivé après un plus récent
     * (fermeture d'onglet, réseau), n'écrit plus rien. Vide : pas de
     * numéro encore, tout envoi s'applique.
     */
    public function up(): void
    {
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->nullable()->after('days');
        });
    }

    public function down(): void
    {
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
