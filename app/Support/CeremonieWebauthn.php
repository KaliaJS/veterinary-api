<?php

namespace App\Support;

use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Étapes de cérémonie WebAuthn avec les origines autorisées de la
 * configuration — l'origine n'est jamais déduite de l'en-tête Host de
 * la requête (amélioration du portage foodtruck-api, qui passait le
 * host reçu du client).
 *
 * Deux domaines (RP) possibles : celui du front, et celui de l'app
 * iPhone (24.10.2026), qui passe par iOS. Le domaine est choisi à
 * l'émission des options et relu ensuite dans les options en cache,
 * jamais dans la requête de vérification.
 */
class CeremonieWebauthn
{
    /** Domaine des passkeys selon le client qui demande les options. */
    public static function rpId(?string $plateforme): string
    {
        return $plateforme === 'ios' ? config('passkey.app_rp_id') : config('passkey.rp_id');
    }

    /**
     * Origines admises pour un domaine : celles du front pour le sien,
     * sinon celle que signe iOS pour l'app.
     *
     * @return list<string>
     */
    public static function origines(string $rpId): array
    {
        return $rpId === config('passkey.rp_id') ? config('passkey.allowed_origins') : ['https://'.$rpId];
    }

    public static function creation(string $rpId): CeremonyStepManager
    {
        return self::fabrique($rpId)->creationCeremony();
    }

    public static function assertion(string $rpId): CeremonyStepManager
    {
        return self::fabrique($rpId)->requestCeremony();
    }

    private static function fabrique(string $rpId): CeremonyStepManagerFactory
    {
        $fabrique = new CeremonyStepManagerFactory;

        $fabrique->setAllowedOrigins(self::origines($rpId));

        $gestionnaire = AttestationStatementSupportManager::create();
        $gestionnaire->add(NoneAttestationStatementSupport::create());
        $fabrique->setAttestationStatementSupportManager($gestionnaire);

        return $fabrique;
    }
}
