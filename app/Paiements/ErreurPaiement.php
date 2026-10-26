<?php

namespace App\Paiements;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
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

    /**
     * `statutHttp` : le code de la réponse de Stripe, null si aucune réponse
     * n'est arrivée (délai, réseau), 0 si rien n'est parti (clé absente)
     * (26.10.2026).
     */
    public function __construct(string $message, public readonly ?string $codeStripe = null, public readonly ?int $statutHttp = null)
    {
        parent::__construct($message);
    }

    /**
     * Une erreur de Stripe lui-même (5xx) : il la garde 24 h sous la clé
     * d'idempotence, qui est usée (26.10.2026). Un refus de la requête (4xx,
     * dont 409 : la même clé est déjà en cours) n'est pas gardé, la clé sert
     * encore ; sans réponse, non plus.
     */
    public function erreurGardeeParStripe(): bool
    {
        return $this->statutHttp !== null && $this->statutHttp >= 500;
    }

    /**
     * Le prélèvement a pu passer quand même : sans réponse, 409 (la même
     * demande en cours) ou 5xx (26.10.2026). Un autre refus (400, 401…) est net.
     */
    public function resultatIncertain(): bool
    {
        return $this->statutHttp === null || $this->statutHttp === 409 || $this->statutHttp >= 500;
    }

    /** Une panne qui passera (incertaine, ou 429 trop d'appels) : pas un refus de la carte. */
    public function estPassagere(): bool
    {
        return $this->resultatIncertain() || $this->statutHttp === 429;
    }

    /** Un message traduit, ou le générique ; le détail de Stripe au journal. */
    public static function depuisStripe(ApiErrorException $e, ?string $traduction): self
    {
        if ($traduction === null) {
            Log::warning('Stripe a refusé un appel.', ['code' => $e->getStripeCode(), 'message' => $e->getMessage(), 'requete' => $e->getRequestId()]);
        }

        // Sans code : une coupure du réseau, ou une erreur levée avant tout envoi
        return new self($traduction ?? self::GENERIQUE, $e->getStripeCode(), $e->getHttpStatus() ?? ($e instanceof ApiConnectionException ? null : 0));
    }

    public function estGenerique(): bool
    {
        return $this->getMessage() === self::GENERIQUE;
    }
}
