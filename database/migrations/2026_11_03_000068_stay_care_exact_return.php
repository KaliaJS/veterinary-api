<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sixième vérification (03.11.2026). Le journal garde de quoi rendre à
 * l'identique ce qu'une prise a sorti : l'unité de dose, le flacon entamé
 * d'avant (numéro, date, ce qui y a été pris) et celui d'après ; une sortie
 * rendue est marquée. Une prise annulée peut se noter de nouveau : chaque
 * annulation reste dans l'historique de la prise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table): void {
            $table->string('dose_unit', 16)->nullable()->comment('Unité de la dose, figée au mouvement');
            $table->unsignedInteger('opening_number')->nullable()->comment('Flacon entamé concerné (après une sortie)');
            $table->unsignedInteger('previous_opening_number')->nullable()->comment('Flacon entamé avant la sortie');
            $table->timestamp('previous_opened_at')->nullable()->comment('Date d’ouverture du flacon entamé avant la sortie');
            $table->decimal('from_opened', 10, 3)->nullable()->comment('Dose prise dans le flacon entamé d’avant');
            $table->timestamp('reversed_at')->nullable()->comment('Sortie rendue (prise annulée)');
        });
        Schema::create('stay_care_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sejour_soin_id')->constrained('sejour_soins')->cascadeOnDelete();
            $table->timestamp('given_at')->nullable();
            $table->string('given_by_name')->nullable();
            $table->decimal('dose_amount', 10, 3)->nullable();
            $table->string('dose_unit', 16)->nullable();
            $table->timestamp('late_noted_at')->nullable();
            $table->timestamp('cancelled_at');
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_name')->nullable();
            $table->boolean('cancelled_late')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_care_cancellations');
        Schema::table('mouvements_stock', function (Blueprint $table): void {
            $table->dropColumn(['dose_unit', 'opening_number', 'previous_opening_number', 'previous_opened_at', 'from_opened', 'reversed_at']);
        });
    }
};
