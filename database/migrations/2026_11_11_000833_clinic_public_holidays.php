<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les jours fériés de la clinique (Jim, 11.11.2026, É23) : ceux de son
     * canton (d'après son NPA, annexe du SECO), sa région quand le canton en
     * a, et ses ajustements (jours travaillés, jours chômés en plus).
     */
    public function up(): void
    {
        Schema::table('cliniques', function (Blueprint $table) {
            $table->string('jours_feries_variante', 40)->nullable();
            $table->json('jours_feries_retires')->nullable();
            $table->json('jours_feries_ajoutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cliniques', function (Blueprint $table) {
            $table->dropColumn(['jours_feries_variante', 'jours_feries_retires', 'jours_feries_ajoutes']);
        });
    }
};
