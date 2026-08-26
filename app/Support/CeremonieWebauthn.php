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
 */
class CeremonieWebauthn
{
    public static function creation(): CeremonyStepManager
    {
        return self::fabrique()->creationCeremony();
    }

    public static function assertion(): CeremonyStepManager
    {
        return self::fabrique()->requestCeremony();
    }

    private static function fabrique(): CeremonyStepManagerFactory
    {
        $fabrique = new CeremonyStepManagerFactory;

        $fabrique->setAllowedOrigins(config('passkey.allowed_origins'));

        $gestionnaire = AttestationStatementSupportManager::create();
        $gestionnaire->add(NoneAttestationStatementSupport::create());
        $fabrique->setAttestationStatementSupportManager($gestionnaire);

        return $fabrique;
    }
}
