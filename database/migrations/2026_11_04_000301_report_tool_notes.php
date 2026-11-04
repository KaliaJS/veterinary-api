<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les lignes notées par les calculateurs dans le compte rendu d'une
 * consultation (vérification du 04.11.2026) : à part du texte SOAP, que le
 * brouillon d'un enregistrement réécrit en entier ; ajoutées côté serveur,
 * l'une après l'autre, jamais remplacées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_rendus', function (Blueprint $table): void {
            $table->text('tool_notes')->nullable()->after('p')->comment('Lignes notées par les calculateurs, une par ligne, dans l’ordre');
        });
    }

    public function down(): void
    {
        Schema::table('comptes_rendus', function (Blueprint $table): void {
            $table->dropColumn('tool_notes');
        });
    }
};
