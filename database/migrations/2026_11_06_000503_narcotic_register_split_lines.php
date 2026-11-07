<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une ligne du registre par lot (décision de Jim, 06.11.2026) : une sortie
 * plus grande que son lot s'inscrit en plusieurs lignes, une par lot. Les
 * lignes suivantes pointent la première : c'est une seule inscription
 * (une remise sur formulaire officiel reste une seule remise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->foreignId('premiere_ligne_id')->nullable()->after('corrige_id')->comment('La première ligne de la même inscription, quand une sortie s’écrit sur plusieurs lots')->constrained('mouvements_stupefiants')->nullOnDelete();
        });
    }

    /** Le registre est un journal légal : le lien entre ses lignes ne s'efface pas */
    public function down(): void
    {
        if (DB::table('mouvements_stupefiants')->whereNotNull('premiere_ligne_id')->exists()) {
            throw new RuntimeException('Le lien entre les lignes d’une même inscription au registre ne se défait pas : des lignes le portent.');
        }
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('premiere_ligne_id');
        });
    }
};
