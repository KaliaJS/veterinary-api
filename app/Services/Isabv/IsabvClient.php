<?php

namespace App\Services\Isabv;

use App\Enums\IsabvForm;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Interface REST de l'OSAV pour les logiciels de cabinet (SI ABV, Swagger
 * 3.0.0, directives techniques ch. 12, 08.11.2026), au nom d'une clinique :
 * son IDE, son complément et son token donnent un JWT (valable 10 secondes
 * par défaut, ch. 12.1.3), passé en en-tête « Authorization: Bearer ».
 * Toute réponse de forme inattendue compte comme une panne : on ne sait pas
 * ce qui est arrivé, et le protocole de communication le dira.
 */
class IsabvClient
{
    /** Le JWT se redemande avant ses 10 secondes */
    private const JWT_SECONDS = 8;

    private ?string $jwt = null;

    private float $jwtAt = 0;

    public function __construct(
        private readonly string $uid,
        private readonly ?string $appendix,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    public static function for(string $uid, ?string $appendix, #[\SensitiveParameter] string $token): self
    {
        return new self($uid, $appendix, $token);
    }

    /** Demande un JWT : l'OSAV vérifie l'IDE, le complément et le token. */
    public function jwt(): string
    {
        if ($this->jwt !== null && microtime(true) - $this->jwtAt < self::JWT_SECONDS) {
            return $this->jwt;
        }
        $response = $this->send(fn (PendingRequest $http): Response => $http->accept('text/plain, application/json')->withBody((string) json_encode(array_filter([
            'uid' => $this->uid,
            'token' => $this->token,
            'appendix' => $this->appendix,
        ], fn ($value) => $value !== null && $value !== '')), 'application/json; charset=utf-8')->post('/jwt/create'));
        if (in_array($response->status(), [400, 401], true)) {
            throw new IsabvTokenRefused;
        }
        $jwt = trim($response->body(), " \n\r\t\"");
        if ($response->failed() || substr_count($jwt, '.') !== 2) {
            throw new IsabvUnavailable;
        }
        $this->jwt = $jwt;
        $this->jwtAt = microtime(true);

        return $jwt;
    }

    /** Envoie une prescription (le XML de son formulaire) ; OK ou NOK selon le protocole rendu. */
    public function createPrescription(IsabvForm $form, string $xml): IsabvResult
    {
        $response = $this->authorized(fn (PendingRequest $http): Response => $http->withBody($xml, 'application/xml; charset=utf-8')
            ->post('/psw/prescription/'.$form->value));
        if ($response->status() === 201) {
            $body = $response->json();
            if (! is_array($body)) {
                throw new IsabvUnavailable;
            }

            $result = IsabvResult::fromProtocol(is_array($body['protocol'] ?? null) ? $body['protocol'] : null, true);

            // `duplicate` : reçue, mais écartée comme doublon (AV, FZL ; directives, ch. 5.1.3)
            return ($body['duplicate'] ?? false) === true ? new IsabvResult($result->isOk, $result->uuid, $result->message, $result->ignoredFields, true) : $result;
        }
        if ($response->status() === 400) {
            $protocol = $response->json();
            $result = IsabvResult::fromProtocol(is_array($protocol) ? $protocol : null, false);

            return new IsabvResult(false, $result->uuid, $result->message ?? 'L’OSAV refuse cette déclaration.', $result->ignoredFields);
        }
        if ($response->status() === 409) {
            return new IsabvResult(false, null, 'L’OSAV demande au moins une préparation à déclarer dans cette prescription.');
        }
        throw new IsabvUnavailable;
    }

    /** Désactive une prescription envoyée ; vrai aussi si l'OSAV ne la connaît pas (400 : rien à désactiver). */
    public function deactivate(string $recipeNumber): bool
    {
        $response = $this->authorized(fn (PendingRequest $http): Response => $http->withBody('', 'application/xml; charset=utf-8')
            ->post('/psw/prescription/deactivate/'.rawurlencode($recipeNumber)));
        if ($response->status() === 200 || $response->status() === 400) {
            return true;
        }
        throw new IsabvUnavailable;
    }

    /**
     * Ce que le protocole de communication sait d'une prescription : la
     * dernière entrée reçue, ou null si l'OSAV ne l'a jamais reçue.
     */
    public function protocolFor(string $recipeNumber): ?IsabvResult
    {
        $response = $this->authorized(fn (PendingRequest $http): Response => $http->withBody((string) json_encode(['prescriptionID' => $recipeNumber]), 'application/json; charset=utf-8')
            ->post('/psw/protocol'));
        $body = $response->json();
        if ($response->status() !== 200 || ! is_array($body)) {
            throw new IsabvUnavailable;
        }
        $entries = array_is_list($body) ? $body : [$body];
        $entries = array_values(array_filter($entries, fn ($entry) => is_array($entry) && ($entry['prescriptionId'] ?? $entry['prescriptionID'] ?? null) === $recipeNumber));
        if ($entries === []) {
            return null;
        }
        usort($entries, fn (array $a, array $b) => strcmp((string) ($a['createdOn'] ?? ''), (string) ($b['createdOn'] ?? '')));

        return IsabvResult::fromProtocol(end($entries), false);
    }

    /** Le nombre de consultations d'une espèce et d'une année ; un nouvel envoi remplace l'ancien à l'OSAV. */
    public function saveConsultation(int $year, string $species, int $count): IsabvResult
    {
        $response = $this->authorized(fn (PendingRequest $http): Response => $http->withBody((string) json_encode([
            'year' => $year,
            'animalType' => $species,
            'consultationPerYear' => $count,
        ]), 'application/json; charset=utf-8')->post('/psw/saveConsultation'));
        if (in_array($response->status(), [200, 201], true)) {
            $protocol = $response->json();

            return IsabvResult::fromProtocol(is_array($protocol) ? $protocol : null, true);
        }
        if ($response->status() === 400) {
            return new IsabvResult(false, null, 'L’OSAV refuse ce nombre de consultations.');
        }
        throw new IsabvUnavailable;
    }

    /**
     * Les prescriptions AV et FZL que l'OSAV a désactivées comme doublons et
     * qui peuvent être réactivées (directives, ch. 5.1.4).
     *
     * @return list<string>
     */
    public function duplicates(): array
    {
        $response = $this->authorized(fn (PendingRequest $http): Response => $http->withBody((string) json_encode($this->office()), 'application/json; charset=utf-8')
            ->post('/psw/prescription/getDuplicatePrescriptions'));
        $ids = $response->json('prescriptionIDs');
        if ($response->status() !== 200 || ! is_array($ids)) {
            throw new IsabvUnavailable;
        }

        return array_values(array_filter($ids, 'is_string'));
    }

    /** @param list<string> $recipeNumbers */
    public function reactivate(array $recipeNumbers): IsabvResult
    {
        $response = $this->authorized(fn (PendingRequest $http): Response => $http->withBody((string) json_encode([...$this->office(), 'prescriptionIDs' => $recipeNumbers]), 'application/json; charset=utf-8')
            ->post('/psw/prescription/reactivateDuplicatePrescriptions'));
        $body = $response->json();
        if (in_array($response->status(), [200, 400], true) && is_array($body)) {
            return IsabvResult::fromProtocol($body, $response->status() === 200);
        }
        throw new IsabvUnavailable;
    }

    /** @return array<string, string> */
    private function office(): array
    {
        return array_filter(['uid' => $this->uid, 'appendix' => (string) $this->appendix], fn (string $value) => $value !== '');
    }

    /** Un appel avec le JWT ; un JWT expiré entre-temps (401) se redemande une fois. */
    private function authorized(callable $call): Response
    {
        $response = $this->send(fn (PendingRequest $http): Response => $call($http->withToken($this->jwt())));
        if ($response->status() === 401) {
            $this->jwt = null;
            $response = $this->send(fn (PendingRequest $http): Response => $call($http->withToken($this->jwt())));
            if ($response->status() === 401) {
                throw new IsabvTokenRefused;
            }
        }

        return $response;
    }

    private function send(callable $call): Response
    {
        try {
            $response = $call(Http::baseUrl((string) config('services.isabv.url'))->timeout(20)->connectTimeout(10)->acceptJson());
        } catch (ConnectionException) {
            throw new IsabvUnavailable;
        }
        if ($response->serverError()) {
            throw new IsabvUnavailable;
        }

        return $response;
    }
}
