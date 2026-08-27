<?php

namespace App\Enums;

/**
 * Domaines fonctionnels — la base des permissions. Les rôles
 * (configurables par organisation) accordent des domaines ; les
 * dérogations par employé ajustent au cas par cas.
 */
enum DomainePermission: string
{
    case Planning = 'planning';
    case Patients = 'patients';
    case Facturation = 'facturation';
    case Stocks = 'stocks';
    case Bloc = 'bloc';
    case Equipe = 'equipe';
    case Organisation = 'organisation';

    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_column(self::cases(), 'value');
    }
}
