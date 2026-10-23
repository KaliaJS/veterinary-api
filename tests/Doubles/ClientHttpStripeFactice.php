<?php

namespace Tests\Doubles;

use Stripe\HttpClient\ClientInterface;
use Throwable;

/**
 * Client HTTP du SDK Stripe qui note les requêtes au lieu de les envoyer
 * (`ApiRequestor::setHttpClient`). Chaque réponse se programme dans
 * l'ordre (`repondre`, `echouer`, `couper`) ; sans programme, une réponse
 * plausible selon le chemin (intention, remboursement, lecteur…).
 */
class ClientHttpStripeFactice implements ClientInterface
{
    /** @var list<array{methode: string, chemin: string, entetes: array<string, string>, params: array<string, mixed>, mode: string}> */
    public array $requetes = [];

    /** @var list<array{0: int, 1: array<string, mixed>}|Throwable> */
    private array $programme = [];

    /** @param  array<string, mixed>  $corps */
    public function repondre(array $corps, int $statut = 200): static
    {
        $this->programme[] = [$statut, $corps];

        return $this;
    }

    /** Stripe répond par une erreur : `type`, `code`, `message` comme dans l'API. */
    public function echouer(int $statut, string $type, ?string $code, string $message = 'Something went wrong on Stripe.'): static
    {
        return $this->repondre(['error' => array_filter(['type' => $type, 'code' => $code, 'message' => $message])], $statut);
    }

    /** Le réseau lâche : le SDK lève l'exception donnée (ex. ApiConnectionException). */
    public function couper(Throwable $erreur): static
    {
        $this->programme[] = $erreur;

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $entetes = [];
        foreach ($headers as $ligne) {
            [$nom, $valeur] = array_pad(explode(':', $ligne, 2), 2, '');
            $entetes[trim($nom)] = trim($valeur);
        }

        $chemin = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requetes[] = ['methode' => $method, 'chemin' => $chemin, 'entetes' => $entetes, 'params' => $params ?? [], 'mode' => $apiMode];

        $reponse = array_shift($this->programme) ?? [200, $this->parDefaut($method, $chemin)];
        if ($reponse instanceof Throwable) {
            throw $reponse;
        }

        return [json_encode($reponse[1]), $reponse[0], ['Request-Id' => 'req_test']];
    }

    /** La dernière requête envoyée. */
    public function derniere(): array
    {
        return $this->requetes[array_key_last($this->requetes)];
    }

    /** @return array<string, mixed> */
    private function parDefaut(string $methode, string $chemin): array
    {
        return match (true) {
            str_starts_with($chemin, '/v1/terminal/readers') => ['id' => 'tmr_test', 'object' => 'terminal.reader', 'label' => 'Comptoir', 'device_type' => 'bbpos_wisepos_e', 'serial_number' => 'WSC-1', 'status' => 'online', 'deleted' => $methode === 'delete' ? true : null],
            str_starts_with($chemin, '/v1/test_helpers/terminal/readers') => ['id' => 'tmr_test', 'object' => 'terminal.reader', 'status' => 'online'],
            str_starts_with($chemin, '/v1/terminal/locations') => ['id' => 'tml_test', 'object' => 'terminal.location'],
            str_starts_with($chemin, '/v1/refunds') => ['id' => 're_test', 'object' => 'refund', 'status' => 'succeeded'],
            str_starts_with($chemin, '/v1/payment_intents') => ['id' => 'pi_test', 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'client_secret' => 'pi_test_secret_test'],
            str_starts_with($chemin, '/v1/account_sessions') => ['object' => 'account_session', 'client_secret' => 'accs_secret_test'],
            str_starts_with($chemin, '/v1/accounts') => ['id' => 'acct_societe', 'object' => 'account', 'charges_enabled' => true, 'payouts_enabled' => true, 'requirements' => ['currently_due' => [], 'past_due' => [], 'disabled_reason' => null]],
            str_starts_with($chemin, '/v2/core/accounts') => ['id' => 'acct_nouveau', 'object' => 'v2.core.account'],
            default => ['id' => 'obj_test', 'object' => 'unknown'],
        };
    }
}
