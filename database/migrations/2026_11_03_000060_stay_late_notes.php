<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Note tardive (décision de Jim, 03.11.2026) : un oubli se note sur une
 * feuille close, sans limite. La ligne garde l'heure réelle du geste
 * (jour, minute, ou fait le) et `late_noted_at`, l'instant où il a été
 * noté après coup ; l'écran dit « noté après coup, le … à …, par … ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->dateTime('late_noted_at')->nullable()->comment('Noté après coup, sur une feuille close');
        });
        Schema::table('stay_pain_scores', function (Blueprint $table): void {
            $table->dateTime('late_noted_at')->nullable()->comment('Noté après coup, sur une feuille close');
        });
        Schema::table('stay_observations', function (Blueprint $table): void {
            $table->dateTime('late_noted_at')->nullable()->comment('Noté après coup, sur une feuille close');
        });
        Schema::table('sejour_constantes', function (Blueprint $table): void {
            $table->dateTime('late_noted_at')->nullable()->comment('Noté après coup, sur une feuille close');
        });
    }

    public function down(): void
    {
        Schema::table('sejour_constantes', fn (Blueprint $table) => $table->dropColumn('late_noted_at'));
        Schema::table('stay_observations', fn (Blueprint $table) => $table->dropColumn('late_noted_at'));
        Schema::table('stay_pain_scores', fn (Blueprint $table) => $table->dropColumn('late_noted_at'));
        Schema::table('sejour_soins', fn (Blueprint $table) => $table->dropColumn('late_noted_at'));
    }
};
