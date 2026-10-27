<?php

namespace App\Paiements;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/** Stripe Identity sur le compte de la plateforme (redirection vers la page de Stripe). */
class PasserelleIdentiteStripe implements PasserelleIdentite
{
    public function __construct(private StripeClient $client) {}

    public function creerSession(string $reference, string $urlRetour, array $metadonnees): array
    {
        $session = $this->appel(fn () => $this->client->identity->verificationSessions->create([
            'type' => 'document',
            'options' => ['document' => [
                'allowed_types' => ['passport', 'id_card', 'driving_license'],
                'require_live_capture' => true,
                'require_matching_selfie' => true,
            ]],
            'return_url' => $urlRetour,
            'metadata' => $metadonnees,
        ], ['idempotency_key' => 'identite-'.$reference]));

        return ['id' => $session->id, 'url' => $session->url];
    }

    public function lireSession(string $id): array
    {
        $session = $this->appel(fn () => $this->client->identity->verificationSessions->retrieve($id, ['expand' => ['verified_outputs']]));
        $sortie = $session->verified_outputs;

        return [
            'id' => $session->id,
            'statut' => $session->status,
            'url' => $session->url,
            'erreur' => $session->last_error?->code,
            'prenom' => is_object($sortie) ? $sortie->first_name : null,
            'nom' => is_object($sortie) ? $sortie->last_name : null,
        ];
    }

    public function annulerSession(string $id): void
    {
        $this->appel(fn () => $this->client->identity->verificationSessions->cancel($id));
    }

    public function effacerSession(string $id): void
    {
        $this->appel(fn () => $this->client->identity->verificationSessions->redact($id));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $appel
     * @return T
     */
    private function appel(callable $appel): mixed
    {
        try {
            return $appel();
        } catch (ApiErrorException $e) {
            throw ErreurPaiement::depuisStripe($e, null);
        }
    }
}
