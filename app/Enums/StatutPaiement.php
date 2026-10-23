<?php

namespace App\Enums;

/**
 * Tentative de paiement Stripe en caisse (TPE ou TWINT) : en attente du
 * client, réussie (la facture est alors encaissée), échouée (carte
 * refusée, lecteur hors ligne…) ou annulée par la caisse. Depuis le
 * 23.10.2026 aussi : remboursée (en entier) et en litige (contestée par
 * le titulaire de la carte).
 */
enum StatutPaiement: string
{
    case EnAttente = 'en_attente';
    case Reussi = 'reussi';
    case Echoue = 'echoue';
    case Annule = 'annule';
    case Rembourse = 'rembourse';
    case Litige = 'litige';

    public function estTermine(): bool
    {
        return $this !== self::EnAttente;
    }

    /** L'argent est passé par Stripe, même rendu ou contesté depuis. */
    public function aEteEncaisse(): bool
    {
        return in_array($this, self::encaisses(), true);
    }

    /** @return list<self> */
    public static function encaisses(): array
    {
        return [self::Reussi, self::Rembourse, self::Litige];
    }
}
