<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Une exception racontée sans valeurs (24.10.2026), pour ce qui la garde
 * hors du journal (table `failed_jobs`) : message passé par MasquageSql,
 * pile réduite aux fichiers, lignes et fonctions, sans arguments. Les
 * exceptions précédentes suivent, masquées de même.
 */
final class ExceptionMasquee extends RuntimeException
{
    private string $recit;

    public function __construct(Throwable $origine)
    {
        parent::__construct(MasquageSql::message($origine->getMessage()), (int) $origine->getCode());
        $this->file = $origine->getFile();
        $this->line = $origine->getLine();
        $this->recit = self::raconter($origine);
    }

    public function __toString(): string
    {
        return $this->recit;
    }

    private static function raconter(Throwable $exception): string
    {
        $parties = [];
        for ($courante = $exception; $courante !== null; $courante = $courante->getPrevious()) {
            $pile = collect($courante->getTrace())
                ->map(fn (array $etape, int $i): string => '#'.$i.' '.($etape['file'] ?? '[interne]').'('.($etape['line'] ?? '?').'): '
                    .($etape['class'] ?? '').($etape['type'] ?? '').$etape['function'].'()')
                ->implode("\n");
            $parties[] = $courante::class.': '.MasquageSql::message($courante->getMessage())
                .' in '.$courante->getFile().':'.$courante->getLine()."\nStack trace:\n".$pile;
        }

        return implode("\n\nPrécédente :\n", $parties);
    }
}
