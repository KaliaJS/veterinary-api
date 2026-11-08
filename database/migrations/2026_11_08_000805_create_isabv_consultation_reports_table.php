<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026) : le nombre de consultations par espèce et par année
 * (O-SI ABV, annexe ch. 2.2.1.5), à déclarer au plus tard le 20 février de
 * l'année suivante (art. 4, al. 2). Un nouvel envoi pour la même espèce et
 * la même année remplace le précédent à l'OSAV (directives, ch. 5.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isabv_consultation_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('species_code', 12);
            $table->unsignedInteger('count');
            $table->string('status', 12)->comment('sent | rejected');
            $table->text('osav_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['clinique_id', 'year', 'species_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isabv_consultation_reports');
    }
};
