<?php

namespace App\Enums;

/**
 * Cycle d'un bon de commande fournisseur : brouillon → envoyée →
 * réceptionnée (docs/data-model.md §14). Une commande réceptionnée a
 * incrémenté le stock — elle ne se supprime plus.
 */
enum StatutCommandeFournisseur: string
{
    case Brouillon = 'draft';
    case Envoyee = 'sent';
    case Receptionnee = 'rec';
}
