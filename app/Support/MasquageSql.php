<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Le message d'une erreur SQL sans les valeurs (24.10.2026). Les liaisons
 * sont déjà masquées (`mask_bindings_in_exception_messages`), mais le
 * message du pilote MySQL répète la valeur fautive : « Duplicate entry
 * 'lea@exemple.ch' for key… », « Incorrect integer value: 'abc' for
 * column… », « Truncated incorrect DOUBLE value: 'abc' ». Au journal, la
 * valeur devient « *** » ; table, colonne et index restent, de quoi
 * comprendre l'erreur. Les tâches en échec passent par le même masque
 * (EchecsMasques), comme les journaux écrits à la main.
 */
final class MasquageSql
{
    public static function message(string $message): string
    {
        return (string) preg_replace(
            // « value: '…' » suivi de « for column », de la fin, ou de la requête
            // (1292 : « Truncated incorrect DOUBLE value: 'abc' (Connection… »)
            ["/Duplicate entry '.*?' for key/s", "/value: '.*?'(?= for | \\(Connection|$)/s"],
            ["Duplicate entry '***' for key", "value: '***'"],
            $message,
        );
    }

    /** Note l'erreur au journal sans valeur ni arguments : fichier, ligne et pile seulement. */
    public static function journaliser(QueryException $e): void
    {
        Log::error(self::message($e->getMessage()), [
            'exception' => QueryException::class,
            'code' => $e->getCode(),
            'lieu' => $e->getFile().':'.$e->getLine(),
            'pile' => collect($e->getTrace())
                ->map(fn (array $etape): string => ($etape['file'] ?? '?').':'.($etape['line'] ?? '?').' '.($etape['class'] ?? '').($etape['type'] ?? '').$etape['function'])
                ->take(30)
                ->all(),
        ]);
    }

    /** @return Closure(QueryException): false */
    public static function rapporteur(): Closure
    {
        return function (QueryException $e): false {
            self::journaliser($e);

            return false;
        };
    }
}
