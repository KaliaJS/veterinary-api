<?php

use App\Models\SejourSoin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les sorties de stock d'avant 000068 (septième vérification, 03.11.2026).
 * Une prise annulée avant elle avait rendu sa sortie sans la marquer : elle
 * est marquée rendue, pour ne jamais revenir une seconde fois. Une sortie
 * sans numéro de flacon le reprend de la prise (000067), quand c'est la
 * dernière sortie de cette prise. Rejouée, elle ne change rien de plus.
 * Huitième vérification : une ancienne annulation se repère aussi par
 * l'historique des annulations, pas seulement par le libellé.
 */
return new class extends Migration
{
    public function up(): void
    {
        $morph = (new SejourSoin)->getMorphClass();
        $takes = DB::table('mouvements_stock')->where('source_type', $morph)->whereNull('reversed_at')
            ->where(fn ($q) => $q->where('quantite', '<', 0)->orWhere('dose', '<', 0))
            ->orderBy('id')->get(['id', 'source_id', 'created_at']);
        foreach ($takes as $take) {
            // Une annulation passée après elle (son retour, ou sa perte notée) l'a déjà rendue ;
            // ou, sans ligne au journal, l'historique des annulations le dit (huitième vérification)
            $isReturned = DB::table('mouvements_stock')->where('source_type', $morph)->where('source_id', $take->source_id)
                ->where('id', '>', $take->id)->where('libelle', 'like', '%Prise annulée%')->exists()
                || (DB::table('stay_care_cancellations')->where('sejour_soin_id', $take->source_id)->where('cancelled_at', '>=', $take->created_at)->exists()
                    && ! $this->isCurrentTake($morph, $take));
            if ($isReturned) {
                DB::table('mouvements_stock')->where('id', $take->id)->update(['reversed_at' => $take->created_at ?? now()]);
            }
        }

        $missing = DB::table('mouvements_stock')->where('source_type', $morph)->whereNull('reversed_at')->whereNull('opening_number')
            ->where('dose', '<', 0)->orderBy('id')->get(['id', 'source_id']);
        foreach ($missing as $take) {
            $isLatest = ! DB::table('mouvements_stock')->where('source_type', $morph)->where('source_id', $take->source_id)
                ->where('dose', '<', 0)->where('id', '>', $take->id)->exists();
            $opening = DB::table('sejour_soins')->where('id', $take->source_id)->value('opening_number');
            if ($isLatest && $opening !== null) {
                DB::table('mouvements_stock')->where('id', $take->id)->update(['opening_number' => $opening]);
            }
        }
    }

    /** La sortie de la prise telle qu'elle est donnée aujourd'hui : jamais marquée rendue */
    private function isCurrentTake(string $morph, object $take): bool
    {
        $soin = DB::table('sejour_soins')->where('id', $take->source_id)->first(['fait', 'cancelled_at']);
        $isGiven = $soin !== null && (bool) $soin->fait && $soin->cancelled_at === null;
        $isLatest = ! DB::table('mouvements_stock')->where('source_type', $morph)->where('source_id', $take->source_id)
            ->where('id', '>', $take->id)->where(fn ($q) => $q->where('quantite', '<', 0)->orWhere('dose', '<', 0))->exists();

        return $isGiven && $isLatest;
    }

    public function down(): void
    {
        // Des marques sur des données : rien à défaire
    }
};
