<?php

namespace App\Services;

use App\Models\Passkey;
use App\Support\CeremonieWebauthn;
use App\Support\PasskeyJsonSerializer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Connexion par passkey (porté de foodtruck-api, adapté à l'API
 * jetons : le défi vit en cache sous un identifiant opaque, la
 * réponse d'authentification est vérifiée contre les origines de la
 * configuration — pas contre l'en-tête Host).
 *
 * Améliorations du portage : suppression du catch vide (et de
 * l'import erroné `PHPUnit\Event\Code\Throwable`, qui n'attrapait
 * rien), défi de 32 octets, options en cache au format JSON avec 422
 * explicite quand le défi est expiré, vérification utilisateur
 * exigée. Contrôle (controler) et consommation (consommer) du défi
 * sont séparés pour que le rattachement clinique soit résolu entre
 * les deux.
 */
class PasskeyAuthService
{
    /** @return array{defi_id: string, options: array<array-key, mixed>} */
    public function generateOptions(?string $email = null): array
    {
        $defiId = (string) Str::ulid();

        $allowedCredentials = $email
            ? Passkey::whereRelation('user', 'email', $email)
                ->get()
                ->map(fn (Passkey $passkey): mixed => $passkey->donnees->getPublicKeyCredentialDescriptor())
                ->all()
            : [];

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: config('passkey.rp_id'),
            allowCredentials: $allowedCredentials,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        Cache::put(
            $this->cleDefi($defiId),
            PasskeyJsonSerializer::serialize($options),
            now()->addMinutes((int) config('passkey.defi_validite_minutes')),
        );

        return [
            'defi_id' => $defiId,
            'options' => PasskeyJsonSerializer::toArray($options),
        ];
    }

    /**
     * Contrôle l'assertion sans consommer le défi : la voie de connexion
     * résout le rattachement clinique avant de consommer(). Une
     * assertion invalide brûle le défi (usage unique face à une
     * tentative ratée) ; une assertion valide le laisse en place.
     *
     * @return array{passkey: Passkey, credentialRecord: CredentialRecord}
     *
     * @throws ValidationException
     */
    public function controler(string $credentialJson, string $defiId): array
    {
        $cle = $this->cleDefi($defiId);
        $optionsJson = Cache::get($cle);

        if (! is_string($optionsJson)) {
            throw ValidationException::withMessages([
                'defi_id' => 'Défi expiré — recommencez la connexion.',
            ]);
        }

        try {
            $publicKeyCredential = $this->deserialiser($credentialJson);

            if (! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
                throw ValidationException::withMessages([
                    'credential' => 'Réponse d’assertion attendue.',
                ]);
            }

            $passkey = Passkey::firstWhere(
                'credential_id',
                Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId),
            );

            if (! $passkey) {
                throw ValidationException::withMessages([
                    'credential' => 'Passkey inconnue — elle a peut-être été révoquée.',
                ]);
            }

            try {
                $credentialRecord = AuthenticatorAssertionResponseValidator::create(CeremonieWebauthn::assertion())->check(
                    credentialRecord: $passkey->donnees,
                    authenticatorAssertionResponse: $publicKeyCredential->response,
                    publicKeyCredentialRequestOptions: PasskeyJsonSerializer::deserialize($optionsJson, PublicKeyCredentialRequestOptions::class),
                    host: config('passkey.rp_id'),
                    userHandle: null,
                );
            } catch (Throwable $exception) {
                report($exception);

                throw ValidationException::withMessages([
                    'credential' => 'Échec de la vérification de la passkey.',
                ]);
            }
        } catch (ValidationException $exception) {
            Cache::forget($cle);

            throw $exception;
        }

        return ['passkey' => $passkey, 'credentialRecord' => $credentialRecord];
    }

    /**
     * Consomme le défi et enregistre le compteur de signatures et les
     * métadonnées (détection de clonage par le validateur au prochain
     * passage).
     */
    public function consommer(string $defiId, Passkey $passkey, CredentialRecord $credentialRecord): void
    {
        Cache::forget($this->cleDefi($defiId));

        $passkey->update([
            'donnees' => $credentialRecord,
            'dernier_usage_le' => now(),
        ]);
    }

    /**
     * Contrôle puis consommation en un temps (hors choix de clinique).
     *
     * @throws ValidationException
     */
    public function validate(string $credentialJson, string $defiId): Passkey
    {
        ['passkey' => $passkey, 'credentialRecord' => $credentialRecord] = $this->controler($credentialJson, $defiId);

        $this->consommer($defiId, $passkey, $credentialRecord);

        return $passkey;
    }

    private function cleDefi(string $defiId): string
    {
        return "passkey-authentication-options-{$defiId}";
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
}
