<?php

namespace App\Enums;

/**
 * Tentative de paiement Stripe en caisse (TPE ou TWINT) : en attente du
 * client, réussie (la facture est alors encaissée), échouée (carte
 * refusée, lecteur hors ligne…) ou annulée par la caisse.
 */
enum StatutPaiement: string
{
    case EnAttente = 'en_attente';
    case Reussi = 'reussi';
    case Echoue = 'echoue';
    case Annule = 'annule';

    public function estTermine(): bool
    {
        return $this !== self::EnAttente;
    }
}
