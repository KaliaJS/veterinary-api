<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une nuit passée se garde telle qu'elle a été (Jim, 11.11.2026, É26 :
     * « Freeze past nights », le registre de l'OLT 1 art. 73) : une nuit
     * suggérée est enregistrée quand elle passe, sans être un choix.
     */
    public function up(): void
    {
        Schema::table('gardes', function (Blueprint $table) {
            $table->boolean('est_choisie')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('gardes', function (Blueprint $table) {
            $table->dropColumn('est_choisie');
        });
    }
};
