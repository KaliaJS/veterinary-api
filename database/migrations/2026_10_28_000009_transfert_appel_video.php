<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfert d'un appel vidéo (décision de Jim, 28.10.2026) : la personne qui
 * mène l'appel (celle qui l'a démarré, ou à qui il a été passé) et le
 * transfert en attente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_teleconsultation', function (Blueprint $table): void {
            $table->foreignId('mene_par_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->foreignId('transfert_vers_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->dateTime('transfert_demande_le')->nullable();
        });
        DB::table('sessions_teleconsultation')->update(['mene_par_id' => DB::raw('demarre_par_id')]);
    }

    public function down(): void
    {
        Schema::table('sessions_teleconsultation', function (Blueprint $table): void {
            $table->dropForeign(['mene_par_id']);
            $table->dropForeign(['transfert_vers_id']);
            $table->dropColumn(['mene_par_id', 'transfert_vers_id', 'transfert_demande_le']);
        });
    }
};
