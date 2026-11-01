<?php

namespace App\Enums;

/**
 * Moyens d'encaissement de la caisse (docs/data-model.md §6) : TPE
 * (Stripe Terminal), TWINT, espèces ; et le virement (01.11.2026), une
 * QR-facture payée depuis la banque du client, qui ne passe jamais par la
 * caisse : hors de l'arrêté et de la clôture.
 */
enum MoyenPaiement: string
{
    case Tpe = 'tpe';
    case Twint = 'twint';
    case Especes = 'cash';
    case Virement = 'virement';

    /** @return list<self> Les moyens qui passent par la caisse (arrêté, clôture, vente au comptoir) */
    public static function deCaisse(): array
    {
        return [self::Tpe, self::Twint, self::Especes];
    }
}
