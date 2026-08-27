<?php

namespace App\Enums;

/**
 * Statuts STOCKÉS d'un devis (docs/data-model.md §7). `expire` est
 * dérivé — un devis envoyé dont la validité est passée — et n'est
 * jamais écrit en base : voir Devis::statutEffectif().
 */
enum StatutDevis: string
{
    case Brouillon = 'brouillon';
    case Envoye = 'envoye';
    case Accepte = 'accepte';
    case Refuse = 'refuse';

    /** Valeur dérivée servie par l'API pour un envoyé hors validité. */
    public const string EXPIRE = 'expire';
}
