<?php

namespace App\Support;

use App\Events\AlertesModifiees;
use App\Models\Clinique;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Diffusion en temps réel (Reverb, 28.10.2026) : toujours après la
 * validation de la transaction, et jamais au prix du geste. Reverb en
 * panne ou un message refusé ne font pas échouer un paiement ni un
 * rendez-vous : l'erreur est signalée, l'écran relira au retour du
 * WebSocket.
 */
final class TempsReel
{
    /** Les alertes de ces cliniques sont à relire. */
    public static function alertes(int ...$cliniqueIds): void
    {
        foreach (array_unique($cliniqueIds) as $id) {
            self::diffuser(fn () => AlertesModifiees::dispatch($id));
        }
    }

    /** Les alertes de toutes les cliniques d'une organisation sont à relire. */
    public static function alertesDeLOrganisation(?int $organisationId): void
    {
        if ($organisationId === null) {
            return;
        }
        self::alertes(...Clinique::query()->withoutGlobalScopes()
            ->whereRelation('societe', 'organisation_id', $organisationId)->pluck('cliniques.id')->map(fn ($id): int => (int) $id)->all());
    }

    /** Reverb en panne : jusqu'à quand on ne le retente pas, dans ce processus */
    private static ?int $coupeJusqua = null;

    private const int PAUSE_APRES_PANNE = 30;

    /**
     * Après une panne, les diffusions suivantes sont sautées 30 s : une vente
     * de cinq produits n'attend pas cinq fois Reverb (2 s chacune) ; les
     * écrans relisent au retour du WebSocket.
     *
     * @param  callable(): void  $diffusion
     */
    public static function diffuser(callable $diffusion): void
    {
        DB::afterCommit(function () use ($diffusion): void {
            if (self::$coupeJusqua !== null && now()->getTimestamp() < self::$coupeJusqua) {
                return;
            }
            try {
                $diffusion();
                self::$coupeJusqua = null;
            } catch (Throwable $e) {
                self::$coupeJusqua = now()->getTimestamp() + self::PAUSE_APRES_PANNE;
                report($e);
            }
        });
    }

    /** Pour les tests : chaque essai repart d'un Reverb joignable. */
    public static function reinitialiser(): void
    {
        self::$coupeJusqua = null;
    }
}
