<?php

namespace App\Paiements;

use RuntimeException;

/**
 * Échec côté prestataire de paiement, avec un message lisible par la
 * caisse et le code Stripe d'origine (ex. `terminal_reader_offline`).
 */
class ErreurPaiement extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $codeStripe = null)
    {
        parent::__construct($message);
    }
}
