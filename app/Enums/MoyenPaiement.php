<?php

namespace App\Enums;

/**
 * Moyens d'encaissement de la caisse (docs/data-model.md §6) : TPE
 * (Stripe Terminal), TWINT, espèces.
 */
enum MoyenPaiement: string
{
    case Tpe = 'tpe';
    case Twint = 'twint';
    case Especes = 'cash';
}
