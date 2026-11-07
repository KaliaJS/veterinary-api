<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remise d'accord unique des lots avec le stock (décision de Jim,
 * 04.11.2026). Jusqu'ici, une vente, un soin ou un −1 baissait le stock
 * sans décompter de lot. Pour chaque article et chaque clinique dont les
 * lots dépassent le stock, les plus anciens sont supposés partis : on
 * retire l'excédent en commençant par la péremption la plus ancienne (les
 * lots sans péremption en dernier), jusqu'à ce que les lots ne dépassent
 * plus le stock. Chaque retrait laisse une trace (`reconciliation`).
 * Relancée, elle ne change plus rien : les lots ne dépassent plus le stock.
 * Écrite sans les modèles, pour rester juste même s'ils changent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $paires = DB::table('lots')->where('quantite', '>', 0)->select('produit_id', 'clinique_id')->distinct()->get();

        foreach ($paires as $paire) {
            DB::transaction(function () use ($paire): void {
                $stock = (int) DB::table('stocks')->where('produit_id', $paire->produit_id)->where('clinique_id', $paire->clinique_id)->lockForUpdate()->value('quantite');
                $lots = DB::table('lots')->where('produit_id', $paire->produit_id)->where('clinique_id', $paire->clinique_id)
                    ->where('quantite', '>', 0)->lockForUpdate()->get()
                    // Les plus anciens d'abord ; les lots sans péremption en dernier
                    ->sortBy(fn (object $lot): array => [$lot->peremption_le === null ? 1 : 0, (string) $lot->peremption_le, (int) $lot->id])
                    ->values();
                $excedent = (int) $lots->sum('quantite') - max(0, $stock);

                foreach ($lots as $lot) {
                    if ($excedent <= 0) {
                        break;
                    }
                    $retire = min($excedent, (int) $lot->quantite);
                    DB::table('lots')->where('id', $lot->id)->update(['quantite' => (int) $lot->quantite - $retire, 'updated_at' => Carbon::now()]);
                    DB::table('lot_allocations')->insert([
                        'kind' => 'reconciliation',
                        'produit_id' => $paire->produit_id,
                        'clinique_id' => $paire->clinique_id,
                        'lot_id' => $lot->id,
                        'lot_number' => $lot->numero_lot,
                        'expires_on' => $lot->peremption_le,
                        'quantity' => -$retire,
                        'created_at' => Carbon::now(),
                    ]);
                    $excedent -= $retire;
                }
            });
        }
    }

    /** Rien à défaire : les lots retirés étaient déjà partis. */
    public function down(): void {}
};
