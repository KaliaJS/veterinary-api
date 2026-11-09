<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * N° BDTA d'une unité d'élevage (SI ABV, 09.11.2026) : 7 chiffres dont le
 * dernier est un chiffre de contrôle (directives techniques de l'OSAV,
 * ch. 12). Le calcul est celui que le serveur de test de l'OSAV applique :
 * modulo 10, poids 3 et 1 depuis la droite. Espaces, points et traits
 * d'union permis à la saisie ; seuls les chiffres partent à l'OSAV.
 */
class NumeroBdta implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('Ce n° BDTA n’est pas juste : 7 chiffres, dont le dernier est un chiffre de contrôle. Recopiez-le depuis les documents de l’exploitation.');
        }
    }

    public static function isValid(string $value): bool
    {
        if (preg_match('/^[\d .\-]+$/', trim($value)) !== 1) {
            return false;
        }
        $digits = self::digits($value);
        if (strlen($digits) !== 7) {
            return false;
        }
        $sum = 0;
        foreach (str_split(strrev(substr($digits, 0, 6))) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $digits[6];
    }

    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
