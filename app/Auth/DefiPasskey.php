<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Conservation des options WebAuthn entre les deux temps d'une
 * cérémonie (options → vérification). L'API est sans session : le
 * défi vit en cache sous un identifiant opaque à usage unique que le
 * client renvoie avec sa réponse d'authentificateur.
 */
class DefiPasskey
{
    private const int VALIDITE_MINUTES = 5;

    public function conserver(PublicKeyCredentialRequestOptions|PublicKeyCredentialCreationOptions $options): string
    {
        $defiId = (string) Str::ulid();

        Cache::put($this->cle($defiId), WebAuthn::toJson($options), now()->addMinutes(self::VALIDITE_MINUTES));

        return $defiId;
    }

    /** @throws ValidationException */
    public function reprendreVerification(string $defiId): PublicKeyCredentialRequestOptions
    {
        return WebAuthn::fromJson($this->reprendre($defiId), PublicKeyCredentialRequestOptions::class);
    }

    /** @throws ValidationException */
    public function reprendreEnregistrement(string $defiId): PublicKeyCredentialCreationOptions
    {
        return WebAuthn::fromJson($this->reprendre($defiId), PublicKeyCredentialCreationOptions::class);
    }

    /** @throws ValidationException */
    private function reprendre(string $defiId): string
    {
        $json = Cache::pull($this->cle($defiId));

        if (! is_string($json)) {
            throw ValidationException::withMessages([
                'defi_id' => 'Défi expiré — recommencez la cérémonie.',
            ]);
        }

        return $json;
    }

    private function cle(string $defiId): string
    {
        return 'auth:passkey:defi:'.$defiId;
    }
}
