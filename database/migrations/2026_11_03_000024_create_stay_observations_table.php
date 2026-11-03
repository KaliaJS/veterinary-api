<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitalisation, la ronde (03.11.2026) : mange, boit, urine, selles,
 * vomit, chacun avec une valeur courte (« moitié », « molles »), l'heure
 * locale et qui l'a noté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sejour_id')->constrained('sejours')->cascadeOnDelete();
            $table->date('day')->comment('Jour local du calendrier');
            $table->unsignedSmallInteger('minute')->comment('Minutes locales du jour (0 à 1439)');
            $table->string('kind', 12)->comment('eats, drinks, urine, stool, vomit');
            $table->string('value', 40);
            $table->foreignId('by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('by_name')->nullable()->comment('Qui l’a noté, tel qu’au jour du geste');
            $table->timestamps();

            $table->index(['sejour_id', 'day', 'minute']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_observations');
    }
};
