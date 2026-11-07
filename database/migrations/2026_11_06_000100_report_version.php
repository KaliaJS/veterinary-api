<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôle de version du compte rendu (choix de Jim, 06.11.2026) : chaque
 * texte SOAP enregistré augmente la version ; un enregistrement parti
 * d'une version dépassée est refusé au lieu d'écraser le texte d'un autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_rendus', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0)->after('tool_notes')->comment('Augmente à chaque texte SOAP enregistré');
            $table->timestamp('texte_modifie_le')->nullable()->after('version')->comment('Dernier texte SOAP enregistré (les lignes des calculateurs n’y comptent pas)');
        });

        // Les comptes rendus existants : leur dernière écriture fait foi
        DB::table('comptes_rendus')->update(['texte_modifie_le' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('comptes_rendus', function (Blueprint $table): void {
            $table->dropColumn(['version', 'texte_modifie_le']);
        });
    }
};
