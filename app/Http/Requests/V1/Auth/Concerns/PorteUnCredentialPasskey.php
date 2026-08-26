<?php

namespace App\Http\Requests\V1\Auth\Concerns;

use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;

/**
 * Désérialisation de la réponse d'authentificateur WebAuthn envoyée
 * par le client (même forme que le package laravel/passkeys).
 */
trait PorteUnCredentialPasskey
{
    protected PublicKeyCredential $publicKeyCredential;

    /** @return array<string, mixed> */
    protected function reglesCredential(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ];
    }

    protected function passedValidation(): void
    {
        try {
            $this->publicKeyCredential = WebAuthn::fromJson(
                json_encode($this->input('credential')) ?: '{}',
                PublicKeyCredential::class,
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'credential' => 'Format de credential invalide.',
            ]);
        }
    }

    public function credential(): PublicKeyCredential
    {
        return $this->publicKeyCredential;
    }
}
