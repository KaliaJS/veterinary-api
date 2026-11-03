<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Neuvième vérification (03.11.2026) : le plus grand numéro de flacon déjà
 * servi et le dernier compte du flacon entamé se gardent sur la ligne de
 * stock, lus et écrits sous son verrou (en MySQL, une lecture simple du
 * journal peut dater d'avant le verrou). Repris depuis le journal pour les
 * lignes existantes ; rejouée, la reprise ne change rien de plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stocks', 'last_opening_number')) {
            Schema::table('stocks', function (Blueprint $table): void {
                $table->unsignedInteger('last_opening_number')->default(0)->comment('Plus grand numéro de flacon déjà servi');
                $table->unsignedBigInteger('last_count_id')->nullable()->comment('Ligne du journal du dernier compte du flacon entamé');
                $table->timestamp('last_counted_at')->nullable()->comment('Heure du dernier compte du flacon entamé');
            });
        }

        DB::table('stocks')->orderBy('id')->each(function (object $stock): void {
            $journal = DB::table('mouvements_stock')->where('produit_id', $stock->produit_id)->where('clinique_id', $stock->clinique_id);
            $count = (clone $journal)->where('libelle', 'Inventaire du flacon entamé')->orderByDesc('id')->first(['id', 'created_at']);
            DB::table('stocks')->where('id', $stock->id)->update([
                'last_opening_number' => max((int) $stock->last_opening_number, (int) $stock->opening_number, (int) (clone $journal)->max('opening_number')),
                'last_count_id' => $count->id ?? $stock->last_count_id,
                'last_counted_at' => $count->created_at ?? $stock->last_counted_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            $table->dropColumn(['last_opening_number', 'last_count_id', 'last_counted_at']);
        });
    }
};
