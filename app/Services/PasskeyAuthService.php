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
use Webauthn\PublicKeyCredentialDescriptor;
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
    public function generateOptions(?string $email = null, ?string $plateforme = null): array
    {
        $defiId = (string) Str::ulid();

        $allowedCredentials = $email
            ? Passkey::whereRelation('user', 'email', $email)
                ->get()
                ->map(fn (Passkey $passkey): mixed => $passkey->donnees->getPublicKeyCredentialDescriptor())
                ->all()
            : [];
        // Adresse inconnue ou sans passkey : des identifiants factices, stables
        // pour une même adresse, plutôt qu'une liste vide qui dirait que le
        // compte n'existe pas (24.10.2026)
        if ($email && $allowedCredentials === []) {
            $allowedCredentials = self::identifiantsFactices($email);
        }

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: CeremonieWebauthn::rpId($plateforme),
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
     * Identifiants factices d'une adresse sans passkey : stables pour une
     * même adresse, et faits comme les vrais (24.10.2026). Leur nombre,
     * leur longueur et leurs transports sont tirés de la répartition des
     * passkeys enregistrées (chiffres agrégés, jamais un vrai identifiant) :
     * aucune forme à part ne dit que le compte n'existe pas.
     *
     * @return list<PublicKeyCredentialDescriptor>
     */
    private static function identifiantsFactices(string $email): array
    {
        $cle = (string) config('app.key');
        $graine = hash_hmac('sha256', 'passkey-factice|'.mb_strtolower(trim($email)), $cle, binary: true);
        $tirage = fn (string $quoi): float => unpack('N', hash_hmac('sha256', $graine.'|'.$quoi, $cle, binary: true))[1] / 4294967296;
        $forme = self::formeDesPasskeys();

        return array_map(function (int $i) use ($graine, $cle, $tirage, $forme): PublicKeyCredentialDescriptor {
            $longueur = (int) self::choisir($forme['longueurs'], $tirage('longueur|'.$i));
            $octets = '';
            for ($bloc = 0; strlen($octets) < $longueur; $bloc++) {
                $octets .= hash_hmac('sha256', $graine.'|id|'.$i.'|'.$bloc, $cle, binary: true);
            }

            return PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                substr($octets, 0, $longueur),
                json_decode((string) self::choisir($forme['transports'], $tirage('transports|'.$i)), true),
            );
        }, range(1, (int) self::choisir($forme['nombres'], $tirage('nombre'))));
    }

    /**
     * Répartition des passkeys enregistrées : nombre par compte, longueur
     * de l'identifiant en octets, transports tels que servis. Recalculée
     * chaque jour ; sans passkey encore, celle du trousseau d'Apple.
     *
     * @return array{nombres: array<int, int>, longueurs: array<int, int>, transports: array<string, int>}
     */
    private static function formeDesPasskeys(): array
    {
        return Cache::remember('passkeys:forme', now()->addDay(), function (): array {
            $forme = [
                'nombres' => Passkey::query()->selectRaw('count(*) as n')->groupBy('user_id')->pluck('n')->map(fn ($n): int => (int) $n)->countBy()->all(),
                'longueurs' => [],
                'transports' => [],
            ];
            foreach (Passkey::query()->select(['id', 'donnees'])->lazyById(200) as $passkey) {
                $descripteur = $passkey->donnees->getPublicKeyCredentialDescriptor();
                $longueur = strlen($descripteur->id);
                $transports = (string) json_encode($descripteur->transports);
                $forme['longueurs'][$longueur] = ($forme['longueurs'][$longueur] ?? 0) + 1;
                $forme['transports'][$transports] = ($forme['transports'][$transports] ?? 0) + 1;
            }

            return [
                'nombres' => $forme['nombres'] ?: [1 => 1],
                'longueurs' => $forme['longueurs'] ?: [20 => 1],
                'transports' => $forme['transports'] ?: ['["hybrid","internal"]' => 1],
            ];
        });
    }

    /**
     * La valeur dont la part cumulée atteint `$tirage` (entre 0 et 1).
     *
     * @param  array<int|string, int>  $poids
     */
    private static function choisir(array $poids, float $tirage): int|string
    {
        ksort($poids);
        $seuil = $tirage * array_sum($poids);
        foreach ($poids as $valeur => $part) {
            $seuil -= $part;
            if ($seuil < 0) {
                return $valeur;
            }
        }

        return array_key_last($poids);
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
                'defi_id' => 'Défi expiré : recommencez la connexion.',
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
                    'credential' => 'Passkey inconnue : elle a peut-être été révoquée.',
                ]);
            }

            $options = PasskeyJsonSerializer::deserialize($optionsJson, PublicKeyCredentialRequestOptions::class);
            // Le domaine choisi à l'émission (front ou app iPhone)
            $rpId = $options->rpId ?? config('passkey.rp_id');

            try {
                $credentialRecord = AuthenticatorAssertionResponseValidator::create(CeremonieWebauthn::assertion($rpId))->check(
                    credentialRecord: $passkey->donnees,
                    authenticatorAssertionResponse: $publicKeyCredential->response,
                    publicKeyCredentialRequestOptions: $options,
                    host: $rpId,
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
