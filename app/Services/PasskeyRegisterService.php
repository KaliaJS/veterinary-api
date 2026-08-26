<?php

namespace App\Services;

use App\Models\User;
use App\Support\CeremonieWebauthn;
use App\Support\PasskeyJsonSerializer;
use Cose\Algorithms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Enrôlement d'une passkey (porté de foodtruck-api, adapté à l'API
 * jetons : l'enrôlement est authentifié, le défi vit en cache sous un
 * identifiant opaque renvoyé au client).
 *
 * Améliorations du portage :
 * - plus de catch vide dans generateOptions (une erreur d'options doit
 *   remonter, pas produire un `null` silencieux) ;
 * - défi cryptographique de 32 octets (au lieu de Str::random) ;
 * - user handle stable et opaque (l'aléa par cérémonie cassait la
 *   déduplication côté authentificateur) ;
 * - excludeCredentials, algorithmes explicites et exigences de
 *   vérification utilisateur ;
 * - options en cache au format JSON (pas d'objet PHP sérialisé), avec
 *   échec explicite (422) quand le défi est expiré ou rejoué.
 */
class PasskeyRegisterService
{
    /** @return array{defi_id: string, options: array<array-key, mixed>} */
    public function generateOptions(User $user): array
    {
        $defiId = (string) Str::ulid();

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(
                name: config('passkey.rp_name'),
                id: config('passkey.rp_id'),
            ),
            user: PublicKeyCredentialUserEntity::create(
                name: $user->email,
                id: $this->userHandle($user),
                displayName: $user->name,
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_EdDSA),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            excludeCredentials: $user->passkeys
                ->map(fn ($passkey): mixed => $passkey->donnees->getPublicKeyCredentialDescriptor())
                ->all(),
        );

        Cache::put(
            "passkey-registration-options-{$defiId}",
            PasskeyJsonSerializer::serialize($options),
            now()->addMinutes((int) config('passkey.defi_validite_minutes')),
        );

        return [
            'defi_id' => $defiId,
            'options' => PasskeyJsonSerializer::toArray($options),
        ];
    }

    /** @throws ValidationException */
    public function validate(string $credentialJson, string $defiId): CredentialRecord
    {
        // Le défi d'abord (consommé — à usage unique) : un défi
        // expiré se signale comme tel, quel que soit le credential.
        $optionsJson = Cache::pull("passkey-registration-options-{$defiId}");

        if (! is_string($optionsJson)) {
            throw ValidationException::withMessages([
                'defi_id' => 'Défi expiré — recommencez l’enrôlement.',
            ]);
        }

        $publicKeyCredential = $this->deserialiser($credentialJson);

        if (! $publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw ValidationException::withMessages([
                'credential' => 'Réponse d’attestation attendue.',
            ]);
        }

        try {
            return AuthenticatorAttestationResponseValidator::create(CeremonieWebauthn::creation())->check(
                authenticatorAttestationResponse: $publicKeyCredential->response,
                publicKeyCredentialCreationOptions: PasskeyJsonSerializer::deserialize($optionsJson, PublicKeyCredentialCreationOptions::class),
                host: config('passkey.rp_id'),
            );
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'credential' => 'Échec de la vérification de la passkey.',
            ]);
        }
    }

    /** @throws ValidationException */
    private function deserialiser(string $credentialJson): PublicKeyCredential
    {
        try {
            return PasskeyJsonSerializer::deserialize($credentialJson, PublicKeyCredential::class);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'credential' => 'Format de credential invalide.',
            ]);
        }
    }

    /**
     * Identifiant de compte opaque et stable exposé aux
     * authentificateurs (jamais l'id ni l'e-mail en clair).
     */
    private function userHandle(User $user): string
    {
        return hash_hmac('sha256', 'passkey-user|'.$user->id, (string) config('app.key'), binary: true);
    }
}
