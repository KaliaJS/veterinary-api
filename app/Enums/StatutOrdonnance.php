<?php

namespace App\Enums;

/**
 * Cycle d'une ordonnance (docs/data-model.md §12) : signée `active`,
 * clôturée `stop` — jamais modifiée après signature, jamais supprimée
 * hors fenêtre d'annulation. Le renouvellement crée une nouvelle
 * ordonnance et clôt la précédente.
 */
enum StatutOrdonnance: string
{
    case Active = 'active';
    case Cloturee = 'stop';
}
