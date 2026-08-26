<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un jeton d'API est émis pour une clinique : toutes les requêtes
     * qu'il authentifie sont scopées dessus (docs/data-model.md,
     * multi-clinique). Nullable en colonne — le middleware refuse les
     * jetons non rattachés sur les routes cliniques.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('clinique_id')->nullable()->after('tokenable_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clinique_id');
        });
    }
};
