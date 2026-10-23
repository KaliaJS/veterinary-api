<?php

namespace App\Paiements;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

/**
 * Échec côté prestataire de paiement, avec un message lisible par la
 * caisse et le code Stripe d'origine (ex. `terminal_reader_offline`).
 * Le message de Stripe lui-même (anglais, parfois technique) n'est
 * jamais montré (23.10.2026) : un code sans traduction donne le message
 * générique, et le détail va au journal.
 */
class ErreurPaiement extends RuntimeException
{
    public const string GENERIQUE = 'Le prestataire de paiement n’a pas pu traiter la demande. Réessayez dans un instant.';

    public function __construct(string $message, public readonly ?string $codeStripe = null)
    {
        parent::__construct($message);
    }

    /** Un message traduit, ou le générique ; le détail de Stripe au journal. */
    public static function depuisStripe(ApiErrorException $e, ?string $traduction): self
    {
        if ($traduction === null) {
            Log::warning('Stripe a refusé un appel.', ['code' => $e->getStripeCode(), 'message' => $e->getMessage(), 'requete' => $e->getRequestId()]);
        }

        return new self($traduction ?? self::GENERIQUE, $e->getStripeCode());
    }

    public function estGenerique(): bool
    {
        return $this->getMessage() === self::GENERIQUE;
    }
}
