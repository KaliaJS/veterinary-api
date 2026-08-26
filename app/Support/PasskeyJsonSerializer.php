<?php

namespace App\Support;

use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * Sérialisation JSON des objets WebAuthn (porté de foodtruck-api).
 * Améliorations du portage : instance de sérialiseur mise en cache
 * (elle était reconstruite à chaque appel), support d'attestation
 * « none » enregistré explicitement, et `toArray()` pour produire la
 * forme navigateur (valeurs nulles omises) attendue par
 * `navigator.credentials`.
 */
class PasskeyJsonSerializer
{
    private static ?SerializerInterface $serializer = null;

    public static function serialize(object $data): string
    {
        return self::serializer()->serialize($data, 'json');
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $into
     * @return T
     */
    public static function deserialize(string $json, string $into): object
    {
        return self::serializer()->deserialize($json, $into, 'json');
    }

    /** @return array<array-key, mixed> */
    public static function toArray(object $data): array
    {
        $serializer = self::serializer();
        assert($serializer instanceof NormalizerInterface);

        return (array) $serializer->normalize($data, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);
    }

    private static function serializer(): SerializerInterface
    {
        if (self::$serializer === null) {
            $gestionnaire = AttestationStatementSupportManager::create();
            $gestionnaire->add(NoneAttestationStatementSupport::create());

            self::$serializer = new WebauthnSerializerFactory($gestionnaire)->create();
        }

        return self::$serializer;
    }
}
