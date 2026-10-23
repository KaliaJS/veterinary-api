<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * N° IDE suisse (« CHE-123.456.789 ») : format et chiffre de contrôle
 * (modulo 11, pondérations 5 4 3 2 7 6 5 4 de l'Office fédéral de la
 * statistique). Un numéro inventé ne part jamais chez Stripe, où il
 * pourrait désigner une vraie entreprise.
 */
class NumeroIde implements ValidationRule
{
    private const array POIDS = [5, 4, 3, 2, 7, 6, 5, 4];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^CHE-\d{3}\.\d{3}\.\d{3}$/', (string) $value)) {
            $fail('Le numéro IDE suit le format « CHE-123.456.789 ».');

            return;
        }

        if (! self::valide((string) $value)) {
            $fail('Ce numéro IDE n’existe pas : vérifiez-le dans le registre (uid.admin.ch).');
        }
    }

    /** Format et chiffre de contrôle justes. */
    public static function valide(?string $ide): bool
    {
        if ($ide === null || ! preg_match('/^CHE-(\d{3})\.(\d{3})\.(\d{3})$/', $ide, $m)) {
            return false;
        }

        $chiffres = array_map('intval', str_split($m[1].$m[2].$m[3]));
        $somme = 0;
        foreach (self::POIDS as $i => $poids) {
            $somme += $chiffres[$i] * $poids;
        }
        $controle = 11 - $somme % 11;

        return $controle !== 10 && ($controle === 11 ? 0 : $controle) === $chiffres[8];
    }
}
