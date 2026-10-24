<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Note l'erreur au journal sans valeur ni arguments : fichier, ligne et
     * pile seulement, l'exception et celles qu'elle emballe (24.10.2026 : une
     * erreur SQL dans une vue arrive emballée par Blade).
     */
    public static function journaliser(Throwable $e): void
    {
        $chaine = [];
        for ($courante = $e; $courante !== null; $courante = $courante->getPrevious()) {
            $chaine[] = $courante::class.': '.self::message($courante->getMessage());
        }

        Log::error(self::message($e->getMessage()), [
            'exception' => $e::class,
            'chaine' => $chaine,
            'code' => $e->getCode(),
            'lieu' => $e->getFile().':'.$e->getLine(),
            'pile' => collect($e->getTrace())
                ->map(fn (array $etape): string => ($etape['file'] ?? '?').':'.($etape['line'] ?? '?').' '.($etape['class'] ?? '').($etape['type'] ?? '').$etape['function'])
                ->take(30)
                ->all(),
        ]);
    }

    /**
     * Toute erreur qui porte une erreur SQL, elle-même ou emballée, passe par
     * le masque ; les autres suivent le journal habituel (null).
     *
     * @return Closure(Throwable): ?false
     */
    public static function rapporteur(): Closure
    {
        return function (Throwable $e): ?bool {
            for ($courante = $e; $courante !== null; $courante = $courante->getPrevious()) {
                if ($courante instanceof QueryException) {
                    self::journaliser($e);

                    return false;
                }
            }

            return null;
        };
    }
}
