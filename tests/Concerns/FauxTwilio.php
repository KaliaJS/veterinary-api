<?php

namespace Tests\Concerns;

use Twilio\AuthStrategy\AuthStrategy;
use Twilio\Http\Client;
use Twilio\Http\Response;
use Twilio\Rest\Client as TwilioClient;

/**
 * Un Twilio d'essai : le client du paquet garde sa configuration (clé,
 * région), mais ses requêtes sont gardées ici au lieu de partir.
 */
trait FauxTwilio
{
    /** @var list<array{method: string, url: string, data: array<string, mixed>, user: ?string, password: ?string}> */
    protected array $requetesTwilio = [];

    protected function fauxTwilio(int $statut = 201, array $reponse = ['sid' => 'SM-essai', 'status' => 'queued']): void
    {
        $this->requetesTwilio = [];
        $faux = new class($this->requetesTwilio, $statut, $reponse) implements Client
        {
            /** @param  array<int, mixed>  $requetes */
            public function __construct(private array &$requetes, private int $statut, private array $reponse) {}

            public function request(string $method, string $url, array $params = [], array $data = [], array $headers = [], ?string $user = null, ?string $password = null, ?int $timeout = null, ?AuthStrategy $authStrategy = null): Response
            {
                $this->requetes[] = ['method' => $method, 'url' => $url, 'data' => $data, 'user' => $user, 'password' => $password];

                return new Response($this->statut, (string) json_encode($this->reponse));
            }
        };
        app(TwilioClient::class)->setHttpClient($faux);
    }
}
