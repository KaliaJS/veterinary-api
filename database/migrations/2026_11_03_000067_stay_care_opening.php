<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De quel flacon entamé vient une prise (cinquième vérification,
 * 03.11.2026) : chaque ouverture d'unité a un numéro et une date, gardés
 * sur le stock et sur la prise. Une prise annulée ne revient que dans le
 * flacon dont elle vient, jamais dans un autre ouvert depuis. L'unité de
 * dose est figée sur la prise, comme le prix et la contenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            $table->unsignedInteger('opening_number')->default(0)->comment('Numéro de la dernière ouverture d’unité');
            $table->timestamp('opened_at')->nullable()->comment('Date d’ouverture de l’unité entamée');
        });
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->unsignedInteger('opening_number')->nullable()->comment('Ouverture du flacon entamé d’où vient la prise');
            $table->string('dose_unit', 16)->nullable()->comment('Unité de dose au moment de la prise');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            $table->dropColumn(['opening_number', 'opened_at']);
        });
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->dropColumn(['opening_number', 'dose_unit']);
        });
    }
};
