<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'écran « Rappels » (01.11.2026, choix A de Jim) et trois rappels de
     * plus, chacun réglable par la clinique : chaque rendez-vous la veille
     * (l'accueil public le promettait), une relance quand un vaccin est en
     * retard, une relance d'un devis resté sans réponse.
     */
    public function up(): void
    {
        Schema::table('cliniques', function (Blueprint $table) {
            $table->boolean('rappels_rendez_vous')->default(true);
            $table->boolean('relance_vaccin_retard')->default(true);
            $table->boolean('relance_devis')->default(true);
        });
        Schema::table('rappels_envoyes', function (Blueprint $table) {
            $table->foreignId('devis_id')->nullable()->after('rendez_vous_id')->constrained('devis')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rappels_envoyes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('devis_id');
        });
        Schema::table('cliniques', function (Blueprint $table) {
            $table->dropColumn(['rappels_rendez_vous', 'relance_vaccin_retard', 'relance_devis']);
        });
    }
};
