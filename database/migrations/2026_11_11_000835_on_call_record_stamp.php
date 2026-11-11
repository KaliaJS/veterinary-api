<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le registre des gardes (É26 à É28) : une nuit enregistrée (le
     * passage du matin, ou une correction) ne change plus ; un choix fait
     * d'avance se vérifie jusqu'à son enregistrement. Qui a choisi ou
     * corrigé la nuit reste écrit.
     */
    public function up(): void
    {
        Schema::table('gardes', function (Blueprint $table) {
            $table->timestamp('enregistree_le')->nullable();
            $table->foreignId('choisie_par_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
        // Les nuits passées déjà écrites (les week-ends repris de l'ancienne table) entrent au registre
        DB::table('gardes')->where('jour', '<', now()->toDateString())->whereNull('enregistree_le')->update(['enregistree_le' => now()]);
    }

    public function down(): void
    {
        Schema::table('gardes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('choisie_par_user_id');
            $table->dropColumn('enregistree_le');
        });
    }
};
