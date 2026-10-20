<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panels demandés d'une analyse — instantané (code, nom, tube) du
     * référentiel au moment de la demande : renommer un panel du
     * référentiel ne réécrit pas une demande passée.
     */
    public function up(): void
    {
        Schema::create('analyse_panels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analyse_id')->constrained()->restrictOnDelete();
            $table->foreignId('panel_labo_id')->constrained('panels_labo')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('nom');
            $table->string('tube', 20);
            $table->timestamps();

            $table->unique(['analyse_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyse_panels');
    }
};
