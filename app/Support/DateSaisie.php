<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Une date saisie « AAAA-MM-JJ » qui existe vraiment (pas le 13e mois ni
 * le 32 janvier) : sinon null, jamais une exception (une saisie mal
 * formée est une erreur de validation, pas du serveur).
 */
final class DateSaisie
{
    public static function lire(mixed $valeur): ?CarbonImmutable
    {
        if (! is_string($valeur) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valeur, $parties) !== 1) {
            return null;
        }

        return checkdate((int) $parties[2], (int) $parties[3], (int) $parties[1]) ? CarbonImmutable::parse($valeur) : null;
    }
}
