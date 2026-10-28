<?php

namespace App\Enums;

/**
 * Une facture est à encaisser (`due`) ou payée — jamais supprimée hors
 * fenêtre d'annulation d'une création (docs/data-model.md §6). Une vente
 * du comptoir non payée s'annule (28.10.2026) : elle reste au journal.
 */
enum StatutFacture: string
{
    case Due = 'due';
    case Payee = 'payee';
    case Annulee = 'annulee';
}
