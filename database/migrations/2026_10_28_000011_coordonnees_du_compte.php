<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les coordonnées du propriétaire gardées sur son compte (28.10.2026) :
 * « Vos coordonnées » de sa fenêtre « Terminer la configuration » se
 * remplissent avant toute fiche de clinique ; ses fiches les reçoivent
 * aussi, comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('tel')->nullable();
            $table->string('rue')->nullable();
            $table->string('npa', 4)->nullable();
            $table->string('localite')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['tel', 'rue', 'npa', 'localite']));
    }
};
