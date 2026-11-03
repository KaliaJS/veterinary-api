<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annuler une prise donnée par erreur (deuxième vérification, 03.11.2026) :
 * la prise reste visible, barrée « annulée par … », avec l'auteur et
 * l'heure ; sur une feuille close, l'annulation est marquée après coup. La
 * dose revient au stock et la facture ne la compte plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->dateTime('cancelled_at')->nullable()->comment('Prise ou soin annulé, donné par erreur');
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_name')->nullable();
            $table->boolean('cancelled_late')->default(false)->comment('Annulé sur une feuille close');
        });
    }

    public function down(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancelled_at', 'cancelled_by_name', 'cancelled_late']);
        });
    }
};
