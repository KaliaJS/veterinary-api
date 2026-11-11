<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les règles des congés de l'organisation (Jim, 11.11.2026) : le droit
     * des nouvelles fiches (4 semaines, le minimum du CO 329a), le seuil et
     * la date des jours reportés, le rappel des deux semaines de suite
     * (CO 329c), et les congés posés sans validation, coupés de base.
     */
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->unsignedTinyInteger('conges_droit_defaut')->default(20);
            $table->unsignedTinyInteger('conges_seuil_report')->default(5);
            $table->string('conges_planifier_avant', 5)->default('03-31');
            $table->boolean('conges_rappel_deux_semaines')->default(true);
            $table->boolean('conges_sans_validation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn(['conges_droit_defaut', 'conges_seuil_report', 'conges_planifier_avant', 'conges_rappel_deux_semaines', 'conges_sans_validation']);
        });
    }
};
