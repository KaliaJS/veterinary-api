<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'ordre des envois d'horaires d'un même onglet, sans horloge (sixième
     * passe, 04.11.2026) : le numéro par horloge (`revision`, migration
     * 000102) laissait un appareil en retard ignoré en silence, et une
     * horloge en avance bloquait la fiche pour tous. On garde maintenant le
     * dernier couple appliqué : l'onglet (`revision_writer`, un uuid tiré à
     * son ouverture) et son compteur (`revision_seq`). Seul un envoi plus
     * ancien du MÊME onglet est ignoré ; entre onglets, la dernière écriture
     * gagne.
     */
    public function up(): void
    {
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->uuid('revision_writer')->nullable()->after('days');
            $table->unsignedInteger('revision_seq')->nullable()->after('revision_writer');
        });
    }

    public function down(): void
    {
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->dropColumn(['revision_writer', 'revision_seq']);
        });
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->nullable()->after('days');
        });
    }
};
