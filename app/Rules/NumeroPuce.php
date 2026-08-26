<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * N° de puce électronique : 15 chiffres, saisis librement (espaces,
 * points…) — même règle que le carnet du front. La valeur stockée est
 * normalisée en cinq groupes de trois (« 756 098 123 456 789 »).
 */
class NumeroPuce implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $chiffres = preg_replace('/\D/', '', (string) $value);

        if (strlen($chiffres) !== 15) {
            $fail('Numéro de puce invalide — 15 chiffres requis ('.strlen($chiffres).'/15).');
        }
    }

    public static function formater(string $valeur): string
    {
        $chiffres = preg_replace('/\D/', '', $valeur);

        return implode(' ', str_split($chiffres, 3));
    }
}
