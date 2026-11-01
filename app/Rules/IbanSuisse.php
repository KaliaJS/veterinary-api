<?php

namespace App\Rules;

use App\Support\QrFacture;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * IBAN suisse ou liechtensteinois (CH, LI ; 21 caractères) : format et
 * clé de contrôle (modulo 97, ISO 13616). Ce sont les seuls comptes
 * admis par la QR-facture (SIX, Swiss Implementation Guidelines 2.3).
 */
class IbanSuisse implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = QrFacture::normaliserIban((string) $value);
        if (! preg_match('/^(CH|LI)\d{7}[A-Z0-9]{12}$/', $iban)) {
            $fail('L’IBAN est suisse ou liechtensteinois : CH ou LI suivi de 19 caractères, par exemple CH93 0076 2011 6238 5295 7.');

            return;
        }
        if (! QrFacture::ibanValide($iban)) {
            $fail('Cet IBAN a une erreur : vérifiez chaque caractère.');
        }
    }
}
