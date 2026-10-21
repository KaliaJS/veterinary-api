<?php

namespace App\Enums;

/** Une ligne de l'historique de vérification d'un vétérinaire. */
enum DecisionVerification: string
{
    case Demandee = 'demandee';
    case Verifie = 'verifie';
    case Refuse = 'refuse';
    case Retire = 'retire';

    public function libelle(): string
    {
        return match ($this) {
            self::Demandee => 'Vérification demandée',
            self::Verifie => 'Vérifié manuellement',
            self::Refuse => 'Vérification refusée',
            self::Retire => 'Badge retiré',
        };
    }
}
