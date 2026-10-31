<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le suivi d'un devis (31.10.2026, fenêtre du devis choisie par Jim) :
 * quand il est parti, quand il a été relancé, quand et par qui il a été
 * refusé, et le motif que le client a pu donner dans son espace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devis', function (Blueprint $table): void {
            $table->dateTime('envoye_le')->nullable()->after('canal');
            $table->dateTime('relance_le')->nullable()->after('envoye_le')->comment('La dernière relance par e-mail');
            $table->dateTime('refuse_le')->nullable()->after('signe_par');
            $table->string('refuse_par')->nullable()->after('refuse_le');
            $table->text('motif_refus')->nullable()->after('refuse_par')->comment('Donné par le client dans son espace, facultatif');
        });
    }

    public function down(): void
    {
        Schema::table('devis', function (Blueprint $table): void {
            $table->dropColumn(['envoye_le', 'relance_le', 'refuse_le', 'refuse_par', 'motif_refus']);
        });
    }
};
