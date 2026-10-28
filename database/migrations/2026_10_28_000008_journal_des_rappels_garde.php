<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal des rappels survit à la suppression d'une injection ou d'un
 * rendez-vous (28.10.2026) : l'e-mail est parti, il reste compté et montré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rappels_envoyes', function (Blueprint $table): void {
            $table->dropForeign(['vaccination_id']);
            $table->dropForeign(['rendez_vous_id']);
            $table->foreign('vaccination_id')->references('id')->on('vaccinations')->nullOnDelete();
            $table->foreign('rendez_vous_id')->references('id')->on('rendez_vous')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rappels_envoyes', function (Blueprint $table): void {
            $table->dropForeign(['vaccination_id']);
            $table->dropForeign(['rendez_vous_id']);
            $table->foreign('vaccination_id')->references('id')->on('vaccinations')->cascadeOnDelete();
            $table->foreign('rendez_vous_id')->references('id')->on('rendez_vous')->cascadeOnDelete();
        });
    }
};
