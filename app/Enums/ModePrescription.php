<?php

namespace App\Enums;

/**
 * Prescription jointe à une facture : délivrée par la clinique (ligne
 * facturée + sortie de stock) ou à retirer en pharmacie (aucune ligne,
 * aucun stock) — docs/data-model.md §6.
 */
enum ModePrescription: string
{
    case Stock = 'stock';
    case Pharmacie = 'pharma';
}
