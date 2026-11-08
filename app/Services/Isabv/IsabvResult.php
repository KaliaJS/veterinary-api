<?php

namespace App\Services\Isabv;

/**
 * Ce que l'OSAV répond à un envoi (protocole de communication) : OK ou NOK,
 * avec son message en français et les champs facultatifs écartés.
 */
final class IsabvResult
{
    /** @param list<string> $ignoredFields */
    public function __construct(
        public readonly bool $isOk,
        public readonly ?string $uuid = null,
        public readonly ?string $message = null,
        public readonly array $ignoredFields = [],
        public readonly bool $isDuplicate = false,
    ) {}

    /** @param array<string, mixed>|null $protocol */
    public static function fromProtocol(?array $protocol, bool $isOkByDefault): self
    {
        $status = is_array($protocol) ? ($protocol['status'] ?? null) : null;
        $isOk = $status === null ? $isOkByDefault : $status === 'OK';
        $message = is_array($protocol) ? self::french($protocol['message'] ?? null) : null;
        $ignored = is_array($protocol) ? self::french($protocol['ignoredOptionalFields'] ?? null) : null;

        return new self(
            isOk: $isOk,
            uuid: is_array($protocol) && is_string($protocol['uuid'] ?? null) ? $protocol['uuid'] : null,
            message: $message,
            ignoredFields: $ignored !== null && $ignored !== '' ? [$ignored] : [],
        );
    }

    /** Le texte français d'un message de l'OSAV (`{de, fr, it}`), sinon l'allemand, sinon le texte tel quel */
    public static function french(mixed $message): ?string
    {
        if (is_string($message)) {
            return trim($message) !== '' ? trim($message) : null;
        }
        if (! is_array($message)) {
            return null;
        }
        foreach (['fr', 'de', 'it'] as $language) {
            if (is_string($message[$language] ?? null) && trim($message[$language]) !== '') {
                return trim($message[$language]);
            }
        }

        return null;
    }
}
