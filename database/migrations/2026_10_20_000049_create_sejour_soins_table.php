<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan de soins d'un séjour (docs/data-model.md §8). `prevu_a` est
     * une heure LOCALE du jour en minutes (comme les horaires
     * d'ouverture — un plan de soins se lit « 08:00, 10:30 » au
     * chevet), pas un datetime UTC. « Soin fait » horodate, signe et
     * sort le stock de la clinique du séjour si un produit est lié.
     */
    public function up(): void
    {
        Schema::create('sejour_soins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sejour_id')->constrained()->cascadeOnDelete();
            $table->date('jour')->comment('Jour local de la clinique');
            $table->unsignedSmallInteger('prevu_a')->comment('Minutes locales du jour (0–1439)');
            $table->string('acte');
            $table->foreignId('produit_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('fait')->default(false);
            $table->dateTime('fait_le')->nullable();
            $table->foreignId('fait_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sejour_id', 'jour', 'prevu_a']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sejour_soins');
    }
};
