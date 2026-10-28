<?php

namespace App\Support;

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
    /** @param  callable(): void  $diffusion */
    public static function diffuser(callable $diffusion): void
    {
        DB::afterCommit(function () use ($diffusion): void {
            try {
                $diffusion();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
